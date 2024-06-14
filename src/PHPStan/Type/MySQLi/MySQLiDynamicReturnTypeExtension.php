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
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function assert;
use function count;
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
		return in_array($methodReflection->getName(), ['query', 'prepare', 'execute_query'], true);
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope,
	): ?Type {
		// TODO: normalized arguments - this doesn't work with named arguments.
		$args = $methodCall->getArgs();
		$queryType = $scope->getType($args[0]->value);
		[$returnClass, $result] = match ($methodReflection->getName()) {
			'query' => [mysqli_result::class, $this->phpstanMysqliHelper->query($queryType)],
			'execute_query' => [
				mysqli_result::class,
				$this->phpstanMysqliHelper->executeQuery(
					$queryType,
					$this->phpstanMysqliHelper->getExecuteParamTypesFromArgument($scope, $args[1] ?? null),
				),
			],
			'prepare' => [mysqli_stmt::class, $this->phpstanMysqliHelper->prepare($queryType)],
			default => [null, null],
		};

		if ($returnClass === null) {
			return null;
		}

		// @phpstan-ignore-next-line This is here for phpstorm
		assert($result === null || $result instanceof QueryPrepareCallResult);
		$analyserResults = $result->analyserResults ?? [];

		if (count($analyserResults) === 0) {
			return new ObjectType($returnClass);
		}

		$params = $this->phpstanHelper->createPhpstanParamsFromMultipleAnalyserResults($analyserResults);
		$types = $this->phpstanHelper->packPHPStanParamsIntoTypes($params);

		return count($types) > 0
			? new GenericObjectType($returnClass, $types)
			: new ObjectType($returnClass);
	}
}
