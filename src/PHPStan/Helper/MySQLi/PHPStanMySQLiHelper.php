<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper\MySQLi;

use InvalidArgumentException;
use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\AnalyserError;
use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\PHPStan\Helper\AnalyserResultPHPStanParams;
use MariaStan\PHPStan\Helper\PHPStanReturnTypeHelper;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_column;
use function array_map;
use function array_merge;
use function count;

use const MYSQLI_ASSOC;
use const MYSQLI_BOTH;
use const MYSQLI_NUM;

final class PHPStanMySQLiHelper
{
	public function __construct(
		private readonly Analyser $analyser,
		private readonly PHPStanReturnTypeHelper $phpstanHelper,
	) {
	}

	public function prepare(Type $queryType): ?QueryPrepareCallResult
	{
		if (! $queryType instanceof ConstantStringType) {
			return null;
		}

		try {
			$analyserResult = $this->analyser->analyzeQuery($queryType->getValue());
		} catch (AnalyserException $e) {
			return new QueryPrepareCallResult(
				[$e->getMessage()],
				null,
			);
		}

		$errors = array_map(static fn (AnalyserError $err) => $err->message, $analyserResult->errors);

		return new QueryPrepareCallResult($errors, $analyserResult);
	}

	public function query(Type $queryType): ?QueryPrepareCallResult
	{
		$result = $this->prepare($queryType);

		if ($result === null || ($result->analyserResult?->positionalPlaceholderCount ?? 0) === 0) {
			return $result;
		}

		return new QueryPrepareCallResult(
			array_merge($result->errors, ['Placeholders cannot be used with query(), use prepared statements.']),
			$result->analyserResult,
		);
	}

	/**
	 * @param array<Type> $executeParamTypes
	 * @return array<string>
	 */
	public function execute(AnalyserResultPHPStanParams $params, array $executeParamTypes): array
	{
		$executeParamCount = count($executeParamTypes);
		$positionalParamsCount = $params->positionalPlaceholderCount->getValue();

		if ($executeParamCount === $positionalParamsCount) {
			return [];
		}

		return [
			"Prepared statement needs {$positionalParamsCount} parameters, got {$executeParamCount}.",
		];
	}

	public function getRowType(AnalyserResultPHPStanParams $params, ?int $mode): Type
	{
		$columns = $this->phpstanHelper->getColumnsFromRowType($params->rowType);

		switch ($mode) {
			case MYSQLI_ASSOC:
				return $this->phpstanHelper->getAssociativeTypeForSingleRow($columns);
			case MYSQLI_NUM:
				return $this->phpstanHelper->getNumericTypeForSingleRow($columns);
			case MYSQLI_BOTH:
				return $this->phpstanHelper->getBothNumericAndAssociativeTypeForSingleRow($columns);
			case null:
				return TypeCombinator::union(
					$this->phpstanHelper->getAssociativeTypeForSingleRow($columns),
					$this->phpstanHelper->getNumericTypeForSingleRow($columns),
					$this->phpstanHelper->getBothNumericAndAssociativeTypeForSingleRow($columns),
				);
			default:
				throw new InvalidArgumentException("Unsupported mode {$mode}.");
		}
	}

	public function getColumnType(AnalyserResultPHPStanParams $params, ?int $column): Type
	{
		$columns = $this->phpstanHelper->getColumnsFromRowType($params->rowType);

		if ($columns === null) {
			return $this->phpstanHelper->getMixedType();
		}

		$types = [];

		if ($column !== null) {
			$types[] = $column < count($columns)
				? $columns[$column][1]
				: new ErrorType();
		} else {
			$types = array_column($columns, 1);
		}

		return TypeCombinator::union(...$types);
	}

	public function fetchColumn(AnalyserResultPHPStanParams $params, ?int $column): Type
	{
		return TypeCombinator::union($this->getColumnType($params, $column), new ConstantBooleanType(false));
	}

	public function fetchArray(AnalyserResultPHPStanParams $params, ?int $mode): Type
	{
		return TypeCombinator::union($this->getRowType($params, $mode), new NullType(), new ConstantBooleanType(false));
	}

	public function fetchAll(AnalyserResultPHPStanParams $params, ?int $mode): Type
	{
		return new ArrayType(new IntegerType(), $this->getRowType($params, $mode));
	}
}
