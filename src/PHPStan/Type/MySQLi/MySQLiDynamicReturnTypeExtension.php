<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\PHPStan\Helper\MySQLi\PHPStanMySQLiHelper;
use MariaStan\PHPStan\Helper\MySQLi\QueryPrepareCallResult;
use MariaStan\PHPStan\Helper\PHPStanReturnTypeHelper;
use mysqli_result;
use mysqli_stmt;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

use function assert;
use function in_array;

class MySQLiDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
	public function __construct(
		private readonly PHPStanReturnTypeHelper $phpstanHelper,
		private readonly PHPStanMySQLiHelper $phpstanMysqliHelper,
	) {
	}

	public function getClass(): string
	{
		return \mysqli::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		return in_array($methodReflection->getName(), ['query', 'prepare'], true);
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope,
	): ?Type {
		$queryType = $scope->getType($methodCall->getArgs()[0]->value);
		[$returnClass, $result] = match ($methodReflection->getName()) {
			'query' => [mysqli_result::class, $this->phpstanMysqliHelper->prepare($queryType)],
			'prepare' => [mysqli_stmt::class, $this->phpstanMysqliHelper->query($queryType)],
			default => [null, null],
		};

		if ($returnClass === null) {
			return null;
		}

		// @phpstan-ignore-next-line This is here for phpstorm
		assert($result === null || $result instanceof QueryPrepareCallResult);
		$params = $this->phpstanHelper->createPHPStanParamsFromAnalyserResult($result?->analyserResult);
		$types = $this->phpstanHelper->packPHPStanParamsIntoTypes($params);

		return new GenericObjectType($returnClass, $types);
	}
}
