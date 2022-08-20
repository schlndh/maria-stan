<?php

declare(strict_types=1);

namespace MariaStan\PHPStan;

use MariaStan\Analyser\QueryResultField;
use MariaStan\PHPStan\Type\MySQLi\DbToPhpstanTypeMapper;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_column;
use function array_values;
use function assert;
use function count;

class PHPStanReturnTypeHelper
{
	public function __construct(private readonly DbToPhpstanTypeMapper $typeMapper)
	{
	}

	/**
	 * This method generates a type that encodes the result fields. It can be attached to the query result (e.g.
	 * mysqli_result) and later used to obtain PHP types for various fetch methods.
	 *
	 * @param array<QueryResultField> $resultFields
	 */
	public function getRowTypeFromFields(array $resultFields): ?Type
	{
		if (count($resultFields) === 0) {
			return null;
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
			$type = $this->typeMapper->mapDbTypeToPhpstanType($field->type);

			if ($field->isNullable) {
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

	/** @param array<array{ConstantStringType, Type}> $columns [[name, type]] */
	public function getNumericTypeForSingleRow(array $columns): ConstantArrayType
	{
		$valueTypes = array_column($columns, 1);

		return new ConstantArrayType($this->getNumberedKeyTypes(count($valueTypes)), $valueTypes);
	}

	/** @param array<array{ConstantStringType, Type}> $columns [[name, type]] */
	public function getAssociativeTypeForSingleRow(array $columns): ConstantArrayType
	{
		[$keyTypes, $valueTypes] = $this->filterDuplicateKeys(
			array_column($columns, 0),
			array_column($columns, 1),
		);

		return new ConstantArrayType($keyTypes, $valueTypes);
	}

	/** @param array<array{ConstantStringType, Type}> $columns [[name, type]] */
	public function getBothNumericAndAssociativeTypeForSingleRow(array $columns): ConstantArrayType
	{
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

		for ($i = 0; $i < count($keyTypes); $i++) {
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
