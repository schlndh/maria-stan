<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use mysqli_result;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ArrayType;
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
			$methodDefinition = ParametersAcceptorSelector::selectSingle(
				$methodReflection->getVariants(),
			);

			return $methodDefinition->getReturnType();
		}

		$rowType = $callerType->getTypes()[0];
		$mode = MYSQLI_BOTH;

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
			case MYSQLI_BOTH:
			default:
				return null;
		}
	}
}
