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
use function in_array;

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
		return in_array(
			$methodReflection->getName(),
			[
				'fetch_all',
				'fetch_row',
				'fetch_array',
				'fetch_column',
			],
			true,
		);
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

		return match ($methodReflection->getName()) {
			'fetch_all' => $this->phpstanMysqliHelper->fetchAll(
				$params,
				$this->extractModeFromFirstArg($methodCall, $scope),
			),
			'fetch_row' => $this->phpstanMysqliHelper->fetchArray($params, MYSQLI_NUM),
			'fetch_array' => $this->phpstanMysqliHelper->fetchArray(
				$params,
				$this->extractModeFromFirstArg($methodCall, $scope),
			),
			'fetch_column' => $this->phpstanMysqliHelper->fetchColumn(
				$params,
				$this->extractColumnFromFirstArg($methodCall, $scope),
			),
			default => null,
		};
	}

	private function extractColumnFromFirstArg(MethodCall $methodCall, Scope $scope): ?int
	{
		if (count($methodCall->getArgs()) === 0) {
			return 0;
		}

		$firstArgType = $scope->getType($methodCall->getArgs()[0]->value);

		if ($firstArgType instanceof ConstantIntegerType) {
			return $firstArgType->getValue();
		}

		return null;
	}

	private function extractModeFromFirstArg(MethodCall $methodCall, Scope $scope): ?int
	{
		if (count($methodCall->getArgs()) === 0) {
			return MYSQLI_NUM;
		}

		$firstArgType = $scope->getType($methodCall->getArgs()[0]->value);

		if ($firstArgType instanceof ConstantIntegerType) {
			return $firstArgType->getValue();
		}

		return null;
	}
}
