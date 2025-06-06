<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper\MySQLi;

use InvalidArgumentException;
use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\AnalyserResult;
use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\PlaceholderTypeProvider\PlaceholderTypeProvider;
use MariaStan\PHPStan\Helper\AnalyserResultPHPStanParams;
use MariaStan\PHPStan\Helper\MariaStanError;
use MariaStan\PHPStan\Helper\MariaStanErrorIdentifiers;
use MariaStan\PHPStan\Helper\PHPStanReturnTypeHelper;
use MariaStan\PHPStan\Helper\PHPStanTypeVarcharPlaceholderTypeProvider;
use PhpParser\Node\Arg;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
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
use function reset;

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

	private function prepareImpl(
		Type $queryType,
		?PlaceholderTypeProvider $placeholderTypeProvider,
	): QueryPrepareCallResult {
		$constantStrings = $queryType->getConstantStrings();

		if (count($constantStrings) === 0) {
			return new QueryPrepareCallResult(
				[
					new MariaStanError(
						"Dynamic SQL: expected query as constant string, got: "
						. $queryType->describe(VerbosityLevel::precise()),
						MariaStanErrorIdentifiers::DYNAMIC_SQL,
					),
				],
				[],
			);
		}

		$analyserResults = [];
		$errors = [];

		foreach ($constantStrings as $sqlType) {
			try {
				$analyserResults[] = $this->analyser->analyzeQuery($sqlType->getValue(), $placeholderTypeProvider);
			} catch (AnalyserException $e) {
				$errors[] = new MariaStanError(
					$e->getMessage(),
					$e->errorType->value,
				);
			}
		}

		$errors = MariaStanError::getUniqueErrors(
			array_merge(
				$errors,
				...array_map(
					static fn (AnalyserResult $r) => MariaStanError::arrayFromAnalyserErrors($r->errors),
					$analyserResults,
				),
			),
		);

		return new QueryPrepareCallResult($errors, $analyserResults);
	}

	public function prepare(Type $queryType): QueryPrepareCallResult
	{
		return $this->prepareImpl($queryType, null);
	}

	public function query(Type $queryType): QueryPrepareCallResult
	{
		$result = $this->prepare($queryType);

		foreach ($result->analyserResults as $analyserResult) {
			if ($analyserResult->positionalPlaceholderCount > 0) {
				return new QueryPrepareCallResult(
					array_merge(
						$result->errors,
						[
							new MariaStanError(
								'Placeholders cannot be used with query(), use prepared statements.',
								MariaStanErrorIdentifiers::UNSUPPORTED_PLACEHOLDER,
							),
						],
					),
					$result->analyserResults,
				);
			}
		}

		return $result;
	}

	/**
	 * @param array<array<Type>> $executeParamTypes possible params
	 * @return list<MariaStanError>
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

		return array_map(
			static fn (string $error) => new MariaStanError(
				$error,
				MariaStanErrorIdentifiers::PLACEHOLDER_MISMATCH,
			),
			array_values(array_unique($errors)),
		);
	}

	/** @param array<array<Type>> $executeParamTypes possible params */
	public function executeQuery(Type $queryType, array $executeParamTypes): QueryPrepareCallResult
	{
		$placeholderTypeProvider = $this->createPlaceholderTypeProviderFromExecuteParamTypes($executeParamTypes);
		$prepareResult = $this->prepareImpl($queryType, $placeholderTypeProvider);
		$phpstanParams = $this->phpstanHelper
			->createPhpstanParamsFromMultipleAnalyserResults($prepareResult->analyserResults);

		if ($phpstanParams === null) {
			return $prepareResult;
		}

		$executeErrors = $this->execute($phpstanParams, $executeParamTypes);

		return new QueryPrepareCallResult(
			array_merge($prepareResult->errors, $executeErrors),
			$prepareResult->analyserResults,
		);
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

	public function getRowTypeFromStringQuery(string $query, int $mode): Type
	{
		$queryResult = $this->query(new ConstantStringType($query));
		$params = $this->phpstanHelper->createPhpstanParamsFromMultipleAnalyserResults($queryResult->analyserResults);

		if ($params === null) {
			return new ErrorType();
		}

		return $this->getRowType($params, $mode);
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

	/** @return array<array<Type>> [possible combinations of params] */
	public function getExecuteParamTypesFromType(Type $type): array
	{
		if ($type->isNull()->yes()) {
			return [[]];
		}

		if ($type instanceof UnionType) {
			$subParams = [];

			foreach ($type->getTypes() as $subtype) {
				$subParams = array_merge($subParams, $this->getExecuteParamTypesFromType($subtype));
			}

			return $subParams;
		}

		return array_map(static fn (ConstantArrayType $t) => $t->getValueTypes(), $type->getConstantArrays());
	}

	/** @return array<array<Type>> [possible combinations of params] */
	public function getExecuteParamTypesFromArgument(Scope $scope, ?Arg $arg): array
	{
		if ($arg === null) {
			return [[]];
		}

		return $this->getExecuteParamTypesFromType($scope->getType($arg->value));
	}

	/** @param array<array<Type>> $executeParamTypes */
	private function createPlaceholderTypeProviderFromExecuteParamTypes(
		array $executeParamTypes,
	): ?PlaceholderTypeProvider {
		if (count($executeParamTypes) === 0) {
			return null;
		}

		$paramsByCount = [];

		foreach ($executeParamTypes as $paramTypes) {
			$paramsByCount[count($paramTypes)][] = $paramTypes;
		}

		// TODO: support different param counts. We'll need to match them to the placeholder count in query.
		if (count($paramsByCount) !== 1) {
			return null;
		}

		$paramTypes = reset($paramsByCount);
		$typesByPosition = [];

		foreach ($paramTypes as $types) {
			foreach (array_values($types) as $i => $type) {
				// The placeholders are indexed starting from 1
				$typesByPosition[$i + 1][] = $type;
			}
		}

		$unionedTypeByPosition = array_map(
			static fn (array $positionTypes) => TypeCombinator::union(...$positionTypes),
			$typesByPosition,
		);

		return new PHPStanTypeVarcharPlaceholderTypeProvider($unionedTypeByPosition);
	}
}
