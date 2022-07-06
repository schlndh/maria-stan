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
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

use function count;

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
				return new ArrayType(new IntegerType(), $rowType);
			case MYSQLI_NUM:
				return new ArrayType(new IntegerType(), $rowType->getValuesArray());
			case MYSQLI_BOTH:
			default:
				$combinedValueTypes = $combinedKeyTypes = [];
				$i = 0;
				$valueTypes = $rowType->getValueTypes();
				$optionalKeys = [];

				foreach ($rowType->getKeyTypes() as $keyType) {
					$combinedKeyTypes[] = new ConstantIntegerType($i);
					$combinedKeyTypes[] = $keyType;
					$combinedValueTypes[] = $valueTypes[$i];
					$combinedValueTypes[] = $valueTypes[$i];

					if ($mode !== MYSQLI_BOTH) {
						$optionalKeys[] = $i * 2;
						$optionalKeys[] = $i * 2 + 1;
					}

					$i++;
				}

				return new ArrayType(
					new IntegerType(),
					new ConstantArrayType($combinedKeyTypes, $combinedValueTypes, [$i], $optionalKeys),
				);
		}
	}
}
