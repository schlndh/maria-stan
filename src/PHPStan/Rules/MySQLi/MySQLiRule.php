<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\AnalyserError;
use MariaStan\Analyser\Exception\AnalyserException;
use mysqli;
use mysqli_stmt;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;

use function array_map;
use function assert;
use function count;

/** @implements Rule<MethodCall> */
class MySQLiRule implements Rule
{
	public function __construct(private readonly Analyser $analyser)
	{
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

		if (! $queryType instanceof ConstantStringType) {
			return [];
		}

		try {
			$analyserResult = $this->analyser->analyzeQuery($queryType->getValue());
		} catch (AnalyserException $e) {
			return [$e->getMessage()];
		}

		$errors = array_map(static fn (AnalyserError $err) => $err->message, $analyserResult->errors);

		if ($methodName === 'query' && $analyserResult->positionalPlaceholderCount > 0) {
			$errors[] = 'Placeholders cannot be used with query(), use prepared statements.';
		}

		return $errors;
	}

	/** @return array<string|RuleError> */
	private function handleMysqliStmtCall(string $methodName, MethodCall $node, Scope $scope): array
	{
		$callerType = $scope->getType($node->var);

		if (! $callerType instanceof GenericObjectType || count($callerType->getTypes()) < 2) {
			return [];
		}

		if ($methodName !== 'execute') {
			return [];
		}

		$remainingPlaceholdersType = $callerType->getTypes()[1];

		if (! $remainingPlaceholdersType instanceof ConstantIntegerType) {
			return [];
		}

		if (count($node->getArgs()) > 0) {
			$paramsType = $scope->getType($node->getArgs()[0]->value);

			if ($paramsType instanceof NullType) {
				$paramsCount = 0;
			} elseif ($paramsType instanceof ConstantArrayType) {
				$paramsCount = count($paramsType->getValueTypes());
			} else {
				return [];
			}
		} else {
			$paramsCount = 0;
		}

		if ($paramsCount === $remainingPlaceholdersType->getValue()) {
			return [];
		}

		return [
			"Prepared statement needs {$remainingPlaceholdersType->getValue()} parameters, got {$paramsCount}.",
		];
	}
}
