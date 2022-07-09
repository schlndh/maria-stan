<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use mysqli_result;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

use function array_column;
use function array_values;
use function assert;
use function count;
use function range;

use const MYSQLI_ASSOC;
use const MYSQLI_BOTH;
use const MYSQLI_NUM;

class MySQLiResultDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
	public function getClass(): string
	{
		return mysqli_result::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		return $methodReflection->getName() === 'fetch_all';
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope,
	): ?Type {
		$callerType = $scope->getType($methodCall->var);

		if (! $callerType instanceof GenericObjectType || count($callerType->getTypes()) !== 1) {
			return null;
		}

		$rowType = $callerType->getTypes()[0];

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

		$mode = null;

		if (count($methodCall->getArgs()) > 0) {
			$firstArgType = $scope->getType($methodCall->getArgs()[0]->value);

			if ($firstArgType instanceof ConstantIntegerType) {
				$mode = $firstArgType->getValue();
			}
		} else {
			$mode = MYSQLI_NUM;
		}

		switch ($mode) {
			case MYSQLI_ASSOC:
				[$keyTypes, $valueTypes] = $this->filterDuplicateKeys(
					array_column($columns, 0),
					array_column($columns, 1),
				);

				return new ArrayType(new IntegerType(), new ConstantArrayType($keyTypes, $valueTypes));
			case MYSQLI_NUM:
				$valueTypes = array_column($columns, 1);

				return new ArrayType(
					new IntegerType(),
					new ConstantArrayType($this->getNumberedKeyTypes(count($valueTypes)), $valueTypes),
				);
			case MYSQLI_BOTH:
			default:
				$combinedValueTypes = $combinedKeyTypes = [];
				$i = 0;
				$optionalKeys = [];

				foreach ($columns as [$keyType, $valueType]) {
					$combinedKeyTypes[] = new ConstantIntegerType($i);
					$combinedKeyTypes[] = $keyType;
					$combinedValueTypes[] = $valueType;
					$combinedValueTypes[] = $valueType;
					$i++;
				}

				[$combinedKeyTypes, $combinedValueTypes] = $this->filterDuplicateKeys(
					$combinedKeyTypes,
					$combinedValueTypes,
				);

				if ($mode !== MYSQLI_BOTH) {
					$optionalKeys = range(0, count($combinedKeyTypes) - 1);
				}

				return new ArrayType(
					new IntegerType(),
					new ConstantArrayType($combinedKeyTypes, $combinedValueTypes, [$i], $optionalKeys),
				);
		}
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
