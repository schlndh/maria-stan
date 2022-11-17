<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Helper\MySQLi\PHPStanMySQLiHelper;
use MariaStan\PHPStan\Helper\PHPStanReturnTypeHelper;
use mysqli;
use mysqli_stmt;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

use function array_merge;
use function assert;
use function count;

/** @implements Rule<MethodCall> */
class MySQLiRule implements Rule
{
	public function __construct(
		private readonly PHPStanReturnTypeHelper $phpstanHelper,
		private readonly PHPStanMySQLiHelper $phpstanMysqliHelper,
	) {
	}

	public function getNodeType(): string
	{
		return MethodCall::class;
	}

	/** @return array<string|RuleError> */
	public function processNode(Node $node, Scope $scope): array
	{
		assert($node instanceof MethodCall);

		if ($node->name instanceof Node\Identifier) {
			$methodName = $node->name->name;
		} else {
			$methodNameType = $scope->getType($node->name);

			if (! $methodNameType instanceof ConstantStringType) {
				return [];
			}

			$methodName = $methodNameType->getValue();
		}

		$objectType = $scope->getType($node->var);

		if (! $objectType instanceof ObjectType) {
			return [];
		}

		return match ($objectType->getClassName()) {
			mysqli::class => $this->handleMysqliCall($methodName, $node, $scope),
			mysqli_stmt::class => $this->handleMysqliStmtCall($methodName, $node, $scope),
			default => [],
		};
	}

	/** @return array<string|RuleError> */
	private function handleMysqliCall(string $methodName, MethodCall $node, Scope $scope): array
	{
		$queryType = $scope->getType($node->getArgs()[0]->value);
		$result = match ($methodName) {
			'query' => $this->phpstanMysqliHelper->query($queryType),
			'prepare' => $this->phpstanMysqliHelper->prepare($queryType),
			default => null,
		};

		return $result?->errors ?? [];
	}

	/** @return array<string|RuleError> */
	private function handleMysqliStmtCall(string $methodName, MethodCall $node, Scope $scope): array
	{
		if ($methodName !== 'execute') {
			return [];
		}

		$callerType = $scope->getType($node->var);

		if (! $callerType instanceof GenericObjectType) {
			return [
				"Dynamic SQL: missing analyser result for mysqli_stmt::{$methodName}() call. Got "
					. $callerType->describe(VerbosityLevel::precise()),
			];
		}

		$params = $this->phpstanHelper->tryUnpackAnalyserResultFromTypes($callerType->getTypes());

		if ($params === null) {
			return [
				"Dynamic SQL: unable to recover analyser result for mysqli_stmt::{$methodName}() call. Got "
					. $callerType->describe(VerbosityLevel::precise()),
			];
		}

		$executeParamTypes = [[]];

		if (count($node->getArgs()) > 0) {
			$paramsType = $scope->getType($node->getArgs()[0]->value);
			$executeParamTypes = $this->getExecuteParamTypesFromType($paramsType);

			if (count($executeParamTypes) === 0) {
				return [];
			}
		}

		return $this->phpstanMysqliHelper->execute($params, $executeParamTypes);
	}

	/** @return array<array<Type>> [possible combinations of params] */
	private function getExecuteParamTypesFromType(Type $type): array
	{
		if ($type instanceof NullType) {
			return [[]];
		}

		if ($type instanceof UnionType) {
			$subParams = [];

			foreach ($type->getTypes() as $subtype) {
				$subParams = array_merge($subParams, $this->getExecuteParamTypesFromType($subtype));
			}

			return $subParams;
		}

		if ($type instanceof ConstantArrayType) {
			return [$type->getValueTypes()];
		}

		return [];
	}
}
