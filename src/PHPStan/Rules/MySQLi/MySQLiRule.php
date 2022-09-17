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
			return [];
		}

		$params = $this->phpstanHelper->tryUnpackAnalyserResultFromTypes($callerType->getTypes());

		if ($params === null) {
			return [];
		}

		$executeParamTypes = [];

		if (count($node->getArgs()) > 0) {
			$paramsType = $scope->getType($node->getArgs()[0]->value);

			if ($paramsType instanceof ConstantArrayType) {
				$executeParamTypes = $paramsType->getValueTypes();
			} elseif (! $paramsType instanceof NullType) {
				return [];
			}
		}

		return $this->phpstanMysqliHelper->execute($params, $executeParamTypes);
	}
}
