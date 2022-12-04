<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use MariaStan\Analyser\AnalyserResult;
use MariaStan\Analyser\QueryResultField;
use MariaStan\PHPStan\Type\MySQLi\DbToPhpstanTypeMapper;
use MariaStan\Schema\DbType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ConstantType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;

use function array_column;
use function array_filter;
use function array_values;
use function assert;
use function count;

class PHPStanReturnTypeHelper
{
	public function __construct(private readonly DbToPhpstanTypeMapper $typeMapper)
	{
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

	/** @return array<Type> */
	public function packPHPStanParamsIntoTypes(?AnalyserResultPHPStanParams $params): array
	{
		return $params !== null
			? [
				TypeCombinator::union(...$params->rowTypes),
				TypeCombinator::union(...$params->positionalPlaceholderCounts),
			]
			: [];
	}

	/** @param array<Type> $types */
	public function tryUnpackAnalyserResultFromTypes(array $types): ?AnalyserResultPHPStanParams
	{
		if (count($types) !== 2) {
			return null;
		}

		$rowTypes = TypeUtils::getConstantTypes($types[0]);
		$rowTypes = array_filter($rowTypes, static fn (ConstantType $t) => $t instanceof ConstantArrayType);
		$placholderCounts = TypeUtils::getConstantIntegers($types[1]);

		if (count($rowTypes) === 0 || count($placholderCounts) === 0) {
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
		if (count($resultFields ?? []) === 0) {
			return new ConstantArrayType([], []);
		}

		$keys = [];
		$values = [];
		$i = 0;
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
	public function getColumnsFromRowType(Type $rowType): ?array
	{
		if (! $rowType instanceof ConstantArrayType) {
			return null;
		}

		/** @var array<array{ConstantStringType, Type}> $columns [[name type, value type]]*/
		$columns = [];

		foreach ($rowType->getValueTypes() as $rowValueType) {
			if (! $rowValueType instanceof ConstantArrayType || count($rowValueType->getValueTypes()) !== 2) {
				return null;
			}

			[$name, $type] = $rowValueType->getValueTypes();

			if (! $name instanceof ConstantStringType) {
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

	/**
	 * @param array<ConstantStringType|ConstantIntegerType> $keyTypes
	 * @param array<Type> $valueTypes
	 * @return array{array<ConstantStringType|ConstantIntegerType>, array<Type>} [filtered keys, filtered values]
	 */
	private function filterDuplicateKeys(array $keyTypes, array $valueTypes): array
	{
		$alreadyUsedNames = [];
		assert(count($keyTypes) === count($valueTypes));
		$keyCount = count($keyTypes);

		for ($i = 0; $i < $keyCount; $i++) {
			if (isset($alreadyUsedNames[$keyTypes[$i]->getValue()])) {
				unset($keyTypes[$i], $valueTypes[$i]);
			} else {
				$alreadyUsedNames[$keyTypes[$i]->getValue()] = 1;
			}
		}

		return [array_values($keyTypes), array_values($valueTypes)];
	}

	/** @return array<ConstantIntegerType> */
	private function getNumberedKeyTypes(int $count): array
	{
		$result = [];

		for ($i = 0; $i < $count; $i++) {
			$result[] = new ConstantIntegerType($i);
		}

		return $result;
	}
}
