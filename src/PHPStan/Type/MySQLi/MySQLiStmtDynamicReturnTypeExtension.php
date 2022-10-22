<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

class MySQLiStmtDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
	public function getClass(): string
	{
		return \mysqli_stmt::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		return $methodReflection->getName() === 'get_result';
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope,
	): ?Type {
		$callerType = $scope->getType($methodCall->var);

		if (! $callerType instanceof GenericObjectType) {
			return null;
		}

		return new GenericObjectType(\mysqli_result::class, $callerType->getTypes());
	}
}
