<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper\MySQLi;

use InvalidArgumentException;
use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\AnalyserError;
use MariaStan\Analyser\AnalyserResult;
use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\PHPStan\Helper\AnalyserResultPHPStanParams;
use MariaStan\PHPStan\Helper\PHPStanReturnTypeHelper;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

use function array_column;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function ksort;

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

	public function prepare(Type $queryType): QueryPrepareCallResult
	{
		$constantStrings = $queryType->getConstantStrings();

		if (count($constantStrings) === 0) {
			return new QueryPrepareCallResult(
				[
					"Dynamic SQL: expected query as constant string, got: "
					. $queryType->describe(VerbosityLevel::precise()),
				],
				[],
			);
		}

		$analyserResults = [];
		$errors = [];

		foreach ($constantStrings as $sqlType) {
			try {
				$analyserResults[] = $this->analyser->analyzeQuery($sqlType->getValue());
			} catch (AnalyserException $e) {
				$errors[] = $e->getMessage();
			}
		}

		$errors = array_unique(
			array_merge(
				$errors,
				...array_map(
					static fn (AnalyserResult $r) => array_map(static fn (AnalyserError $e) => $e->message, $r->errors),
					$analyserResults,
				),
			),
		);

		return new QueryPrepareCallResult($errors, $analyserResults);
	}

	public function query(Type $queryType): QueryPrepareCallResult
	{
		$result = $this->prepare($queryType);

		foreach ($result->analyserResults as $analyserResult) {
			if ($analyserResult->positionalPlaceholderCount > 0) {
				return new QueryPrepareCallResult(
					array_merge(
						$result->errors,
						['Placeholders cannot be used with query(), use prepared statements.'],
					),
					$result->analyserResults,
				);
			}
		}

		return $result;
	}

	/**
	 * @param array<array<Type>> $executeParamTypes possible params
	 * @return array<string>
	 */
	public function execute(AnalyserResultPHPStanParams $params, array $executeParamTypes): array
	{
		$supportedPlaceholderCounts = [];

		foreach ($params->positionalPlaceholderCounts as $count) {
			$supportedPlaceholderCounts[$count->getValue()] = true;
		}

		ksort($supportedPlaceholderCounts);
		$supportedPlaceholderTxt = implode(', ', array_keys($supportedPlaceholderCounts));
		$errors = [];

		foreach ($executeParamTypes as $executeParams) {
			$count = count($executeParams);

			if (isset($supportedPlaceholderCounts[$count])) {
				continue;
			}

			$errors[] = "Prepared statement needs {$supportedPlaceholderTxt} parameters, got {$count}.";
		}

		return array_values(array_unique($errors));
	}

	public function getRowType(AnalyserResultPHPStanParams $params, ?int $mode): Type
	{
		$types = [];

		foreach ($params->rowTypes as $rowType) {
			$columns = $this->phpstanHelper->getColumnsFromRowType($rowType);

			switch ($mode) {
				case MYSQLI_ASSOC:
					$types[] = $this->phpstanHelper->getAssociativeTypeForSingleRow($columns);
					break;
				case MYSQLI_NUM:
					$types[] = $this->phpstanHelper->getNumericTypeForSingleRow($columns);
					break;
				case MYSQLI_BOTH:
					$types[] = $this->phpstanHelper->getBothNumericAndAssociativeTypeForSingleRow($columns);
					break;
				case null:
					$types[] = TypeCombinator::union(
						$this->phpstanHelper->getAssociativeTypeForSingleRow($columns),
						$this->phpstanHelper->getNumericTypeForSingleRow($columns),
						$this->phpstanHelper->getBothNumericAndAssociativeTypeForSingleRow($columns),
					);
					break;
				default:
					throw new InvalidArgumentException("Unsupported mode {$mode}.");
			}
		}

		return TypeCombinator::union(...$types);
	}

	public function getColumnType(AnalyserResultPHPStanParams $params, ?int $column): Type
	{
		$types = [];

		foreach ($params->rowTypes as $rowType) {
			$columns = $this->phpstanHelper->getColumnsFromRowType($rowType);

			if ($columns === null) {
				return $this->phpstanHelper->getMixedType();
			}

			if ($column !== null) {
				$types[] = $column < count($columns)
					? $columns[$column][1]
					: new ErrorType();
			} else {
				$types = array_merge($types, array_column($columns, 1));
			}
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

	public function fetchObject(AnalyserResultPHPStanParams $params, ?string $class): Type
	{
		$rowType = $this->getRowType($params, MYSQLI_ASSOC);
		$baseObjectType = $class !== null
			? new ObjectType($class)
			: new ObjectWithoutClassType();
		$objectType = $this->phpstanHelper->convertConstantArraysToObjectShape($rowType, $baseObjectType);

		return TypeCombinator::union($objectType, new NullType(), new ConstantBooleanType(false));
	}

	public function fetchAll(AnalyserResultPHPStanParams $params, ?int $mode): Type
	{
		return new ArrayType(new IntegerType(), $this->getRowType($params, $mode));
	}
}
