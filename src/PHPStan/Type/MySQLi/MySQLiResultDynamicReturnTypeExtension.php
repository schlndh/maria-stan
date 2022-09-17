<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\PHPStan\Helper\MySQLi\PHPStanMySQLiHelper;
use MariaStan\PHPStan\Helper\PHPStanReturnTypeHelper;
use mysqli_result;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

use function count;

use const MYSQLI_NUM;

class MySQLiResultDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
	public function __construct(
		private readonly PHPStanReturnTypeHelper $phpstanHelper,
		private readonly PHPStanMySQLiHelper $phpstanMysqliHelper,
	) {
	}

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

		if (! $callerType instanceof GenericObjectType) {
			return null;
		}

		$params = $this->phpstanHelper->tryUnpackAnalyserResultFromTypes($callerType->getTypes());

		if ($params === null) {
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

		return $this->phpstanMysqliHelper->fetchAll($params, $mode);
	}
}
