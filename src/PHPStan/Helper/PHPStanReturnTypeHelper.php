<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use MariaStan\Analyser\AnalyserResult;
use MariaStan\Analyser\ColumnInfo;
use MariaStan\Analyser\ColumnInfoTableTypeEnum;
use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\Column;
use MariaStan\DbReflection\DbReflection;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\MariaDbParser;
use MariaStan\PHPStan\Exception\InvalidArgumentException;
use MariaStan\PHPStan\Type\MySQLi\DbToPhpstanTypeMapper;
use MariaStan\Schema\DbType;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectShapeType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

use function array_column;
use function array_search;
use function array_values;
use function assert;
use function count;
use function reset;

class PHPStanReturnTypeHelper
{
	/** @var array<string, array<string, Type|null>> table => column => type */
	private array $columnTypeOverrides;

	/** @var array<array{column: string, type: string}> */
	private array $rawColumnTypeOverrides;

	/** @param array<array{column: string, type: string}> $columnTypeOverrides */
	public function __construct(
		private readonly DbToPhpstanTypeMapper $typeMapper,
		private readonly TypeStringResolver $typeStringResolver,
		private readonly MariaDbParser $mariaDbParser,
		private readonly DbReflection $dbReflection,
		array $columnTypeOverrides,
	) {
		$this->rawColumnTypeOverrides = $columnTypeOverrides;
	}

	/** @param array<AnalyserResult> $analyserResults */
	public function createPhpstanParamsFromMultipleAnalyserResults(array $analyserResults): ?AnalyserResultPHPStanParams
	{
		if (count($analyserResults) === 0) {
			return null;
		}

		$rowTypes = [];
		$placeholderCounts = [];

		foreach ($analyserResults as $analyserResult) {
			if ($analyserResult->resultFields === null || $analyserResult->positionalPlaceholderCount === null) {
				return null;
			}

			$rowTypes[] = $this->getRowTypeFromFields($analyserResult->resultFields);
			$placeholderCounts[] = new ConstantIntegerType($analyserResult->positionalPlaceholderCount);
		}

		return new AnalyserResultPHPStanParams($rowTypes, $placeholderCounts);
	}

	/** @return list<Type> */
	public function packPHPStanParamsIntoTypes(?AnalyserResultPHPStanParams $params): array
	{
		if ($params === null) {
			return [];
		}

		$keyTypes = [];
		$valueTypes = [];
		$idx = 0;

		foreach ($params->rowTypes as $rowType) {
			$keyTypes[] = new ConstantIntegerType($idx);
			$valueTypes[] = $rowType;
			$idx++;
		}

		return [
			new ConstantArrayType($keyTypes, $valueTypes, isList: true),
			TypeCombinator::union(...$params->positionalPlaceholderCounts),
		];
	}

	/** @param array<Type> $types */
	public function tryUnpackAnalyserResultFromTypes(array $types): ?AnalyserResultPHPStanParams
	{
		if (count($types) !== 2) {
			return null;
		}

		$rowTypesArr = $types[0]->getConstantArrays();
		$placholderCounts = TypeUtils::getConstantIntegers($types[1]);

		if (count($rowTypesArr) !== 1 || count($placholderCounts) === 0) {
			return null;
		}

		$rowTypes = [];

		foreach ($rowTypesArr[0]->getValueTypes() as $valueType) {
			$valueTypeArr = $valueType->getConstantArrays();

			if (count($valueTypeArr) !== 1) {
				return null;
			}

			$rowTypes[] = $valueTypeArr[0];
		}

		if (count($rowTypes) === 0) {
			return null;
		}

		return new AnalyserResultPHPStanParams($rowTypes, $placholderCounts);
	}

	/**
	 * This method generates a type that encodes the result fields. It can be attached to the query result (e.g.
	 * mysqli_result) and later used to obtain PHP types for various fetch methods.
	 *
	 * @param array<QueryResultField> $resultFields
	 */
	public function getRowTypeFromFields(?array $resultFields): ConstantArrayType
	{
		if ($resultFields === null || count($resultFields) === 0) {
			return new ConstantArrayType([], []);
		}

		$keys = [];
		$values = [];
		$i = 0;

		/** @var array{ConstantIntegerType, ConstantIntegerType} $colKeyTypes */
		static $colKeyTypes = [
			new ConstantIntegerType(0),
			new ConstantIntegerType(1),
		];

		foreach ($resultFields as $field) {
			$keys[] = new ConstantIntegerType($i);
			$type = $this->typeMapper->mapDbTypeToPhpstanType($field->exprType->type);

			if ($field->exprType->isNullable) {
				$type = TypeCombinator::addNull($type);
			}

			$typeOverride = $this->findColumnTypeOverride($field->exprType->column);

			if ($typeOverride !== null) {
				$type = TypeCombinator::intersect($type, $typeOverride);
			}

			$values[] = new ConstantArrayType(
				$colKeyTypes,
				[
					new ConstantStringType($field->name),
					$type,
				],
			);
			$i++;
		}

		return new ConstantArrayType($keys, $values);
	}

	/** @return ?array<array{ConstantStringType, Type}> [[name, type]] */
	public function getColumnsFromRowType(ConstantArrayType $rowType): ?array
	{
		/** @var array<array{ConstantStringType, Type}> $columns [[name type, value type]]*/
		$columns = [];

		foreach ($rowType->getValueTypes() as $rowValueType) {
			$rowValueTypeConstantArray = $rowValueType->getConstantArrays();
			$rowValueType = reset($rowValueTypeConstantArray);

			if ($rowValueType === false) {
				return null;
			}

			if (count($rowValueType->getValueTypes()) !== 2) {
				return null;
			}

			[$name, $type] = $rowValueType->getValueTypes();
			$nameConstantStrings = $name->getConstantStrings();
			$name = reset($nameConstantStrings);

			if ($name === false) {
				return null;
			}

			$columns[] = [$name, $type];
		}

		return $columns;
	}

	public function getMixedType(): Type
	{
		return TypeCombinator::addNull($this->typeMapper->mapDbTypeToPhpstanType(new DbType\MixedType()));
	}

	/** @param ?array<array{ConstantStringType, Type}> $columns [[name, type]] */
	public function getNumericTypeForSingleRow(?array $columns): Type
	{
		if ($columns === null) {
			return new ArrayType(new IntegerType(), $this->getMixedType());
		}

		$valueTypes = array_column($columns, 1);

		return new ConstantArrayType($this->getNumberedKeyTypes(count($valueTypes)), $valueTypes, count($valueTypes));
	}

	/** @param ?array<array{ConstantStringType, Type}> $columns [[name, type]] */
	public function getAssociativeTypeForSingleRow(?array $columns): Type
	{
		if ($columns === null) {
			return new ArrayType(new StringType(), $this->getMixedType());
		}

		[$keyTypes, $valueTypes] = $this->filterDuplicateKeys(
			array_column($columns, 0),
			array_column($columns, 1),
		);

		return new ConstantArrayType($keyTypes, $valueTypes);
	}

	/** @param ?array<array{ConstantStringType, Type}> $columns [[name, type]] */
	public function getBothNumericAndAssociativeTypeForSingleRow(?array $columns): Type
	{
		if ($columns === null) {
			return new ArrayType(new UnionType([new StringType(), new IntegerType()]), $this->getMixedType());
		}

		$combinedValueTypes = $combinedKeyTypes = [];
		$i = 0;

		foreach ($columns as [$keyType, $valueType]) {
			$combinedKeyTypes[] = new ConstantIntegerType($i);
			$combinedKeyTypes[] = $keyType;
			$combinedValueTypes[] = $valueType;
			$combinedValueTypes[] = $valueType;
			$i++;
		}

		[$combinedKeyTypes, $combinedValueTypes] = $this->filterDuplicateKeys($combinedKeyTypes, $combinedValueTypes);

		return new ConstantArrayType($combinedKeyTypes, $combinedValueTypes, [$i], []);
	}

	public function convertConstantArraysToObjectShape(Type $rowType, Type $baseObjectType): Type
	{
		// Modified from
		// https://github.com/phpstan/phpstan-src/blob/8cdb5c95fd7a7c1a8f47d07637d5ebbbff0bfb86/src/Analyser/MutatingScope.php#L1375
		$constantArrays = $rowType->getConstantArrays();

		if (count($constantArrays) === 0) {
			return $baseObjectType;
		}

		$objects = [];

		foreach ($rowType->getConstantArrays() as $constantArray) {
			$properties = [];
			$optionalProperties = [];

			foreach ($constantArray->getKeyTypes() as $i => $keyType) {
				if (! $keyType instanceof ConstantStringType) {
					// an object with integer properties is >weird<
					continue;
				}

				$valueType = $constantArray->getValueTypes()[$i];
				$optional = $constantArray->isOptionalKey($i);

				if ($optional) {
					$optionalProperties[] = $keyType->getValue();
				}

				$properties[$keyType->getValue()] = $valueType;
			}

			$intersectedObject = TypeCombinator::intersect(
				new ObjectShapeType($properties, $optionalProperties),
				$baseObjectType,
			);

			// This happens if the class is not registered in universalObjectCratesClasses.
			if ($intersectedObject instanceof NeverType) {
				$intersectedObject = $baseObjectType;
			}

			$objects[] = $intersectedObject;
		}

		return TypeCombinator::union(...$objects);
	}

	/**
	 * @param array<ConstantStringType|ConstantIntegerType> $keyTypes
	 * @param array<Type> $valueTypes
	 * @return array{list<ConstantStringType|ConstantIntegerType>, list<Type>} [filtered keys, filtered values]
	 */
	private function filterDuplicateKeys(array $keyTypes, array $valueTypes): array
	{
		$previousNameIdx = [];
		assert(count($keyTypes) === count($valueTypes));
		$keyCount = count($keyTypes);

		for ($i = 0; $i < $keyCount; $i++) {
			$previousIdx = $previousNameIdx[$keyTypes[$i]->getValue()] ?? null;

			if ($previousIdx !== null) {
				$valueTypes[$previousIdx] = $valueTypes[$i];
				unset($keyTypes[$i], $valueTypes[$i]);
			} else {
				$previousNameIdx[$keyTypes[$i]->getValue()] = $i;
			}
		}

		return [array_values($keyTypes), array_values($valueTypes)];
	}

	/** @return list<ConstantIntegerType> */
	private function getNumberedKeyTypes(int $count): array
	{
		$result = [];

		for ($i = 0; $i < $count; $i++) {
			$result[] = new ConstantIntegerType($i);
		}

		return $result;
	}

	private function findColumnTypeOverride(?ColumnInfo $column): ?Type
	{
		if ($column === null || $column->tableType !== ColumnInfoTableTypeEnum::TABLE) {
			return null;
		}

		if (! isset($this->columnTypeOverrides)) {
			$this->columnTypeOverrides = [];

			// This has to be done lazily, otherwise it can lead to circular dependency in the constructor for "X::*"
			foreach ($this->rawColumnTypeOverrides as ['column' => $columnStr, 'type' => $typeStr]) {
				$type = $this->typeStringResolver->resolve($typeStr);
				$expr = null;
				$prevException = null;

				try {
					$expr = $this->mariaDbParser->parseSingleExpression($columnStr);
				} catch (ParserException $prevException) {
				}

				if (! $expr instanceof Column || $expr->tableName === null) {
					throw new InvalidArgumentException(
						"Invalid configuration. Expected table.column got '{$typeStr}'",
						0,
						$prevException,
					);
				}

				$conflictingType = $this->columnTypeOverrides[$expr->tableName][$expr->name] ?? null;

				if ($conflictingType !== null) {
					throw new InvalidArgumentException(
						"Type for '{$typeStr}' was already specified as "
						. $conflictingType->describe(VerbosityLevel::precise()),
					);
				}

				// I add null, so that it can be intersected with nullable columns
				// (e.g. WHERE col IS NULL, LEFT JOIN, ...)
				$this->columnTypeOverrides[$expr->tableName][$expr->name] = TypeCombinator::addNull($type);
			}
		}

		$override = $this->columnTypeOverrides[$column->tableName][$column->name] ?? false;

		if ($override !== false) {
			return $override;
		}

		$override = null;
		$table = null;

		try {
			$table = $this->dbReflection->findTableSchema($column->tableName);
		} catch (DbReflectionException) {
		}

		foreach ($table->foreignKeys ?? [] as $fk) {
			$key = array_search($column->name, $fk->columnNames, true);

			if ($key === false) {
				continue;
			}

			$override = $this->columnTypeOverrides[$fk->referencedTableName][$fk->referencedColumnNames[$key]] ?? null;

			if ($override !== null) {
				break;
			}
		}

		return $this->columnTypeOverrides[$column->tableName][$column->name] = $override;
	}
}
