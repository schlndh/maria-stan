<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Helper\MariaStanError;
use MariaStan\PHPStan\Helper\MariaStanErrorIdentifiers;
use MariaStan\PHPStan\Helper\MySQLi\PHPStanMySQLiHelper;
use MariaStan\PHPStan\Helper\PHPStanReturnTypeHelper;
use mysqli;
use mysqli_stmt;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\VerbosityLevel;

use function assert;
use function count;
use function reset;

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

	/** @inheritDoc */
	public function processNode(Node $node, Scope $scope): array
	{
		assert($node instanceof MethodCall);

		if ($node->name instanceof Node\Identifier) {
			$methodName = $node->name->name;
		} else {
			$methodNameType = $scope->getType($node->name);
			$methodNameConstantStrings = $methodNameType->getConstantStrings();

			if (count($methodNameConstantStrings) !== 1) {
				return [];
			}

			$methodName = reset($methodNameConstantStrings)->getValue();
		}

		$objectType = $scope->getType($node->var);
		$objectClassNames = $objectType->getObjectClassNames();

		if (count($objectClassNames) !== 1) {
			return [];
		}

		return match (reset($objectClassNames)) {
			mysqli::class => $this->handleMysqliCall($methodName, $node, $scope),
			mysqli_stmt::class => $this->handleMysqliStmtCall($methodName, $node, $scope),
			default => [],
		};
	}

	/** @return list<IdentifierRuleError> */
	private function handleMysqliCall(string $methodName, MethodCall $node, Scope $scope): array
	{
		// TODO: normalized arguments - this doesn't work with named arguments.
		$args = $node->getArgs();
		$queryType = $scope->getType($args[0]->value);
		$result = match ($methodName) {
			'query' => $this->phpstanMysqliHelper->query($queryType),
			'execute_query' => $this->phpstanMysqliHelper->executeQuery(
				$queryType,
				$this->phpstanMysqliHelper->getExecuteParamTypesFromArgument($scope, $args[1] ?? null),
			),
			'prepare' => $this->phpstanMysqliHelper->prepare($queryType),
			default => null,
		};

		return MariaStanError::arrayToPHPStanRuleErrors($result->errors ?? []);
	}

	/** @return list<IdentifierRuleError> */
	private function handleMysqliStmtCall(string $methodName, MethodCall $node, Scope $scope): array
	{
		if ($methodName !== 'execute') {
			return [];
		}

		$callerType = $scope->getType($node->var);

		if (! $callerType instanceof GenericObjectType) {
			return [
				MariaStanError::buildPHPSTanRuleError(
					"Dynamic SQL: missing analyser result for mysqli_stmt::{$methodName}() call. Got "
					. $callerType->describe(VerbosityLevel::precise()),
					MariaStanErrorIdentifiers::DYNAMIC_SQL,
				),
			];
		}

		$params = $this->phpstanHelper->tryUnpackAnalyserResultFromTypes($callerType->getTypes());

		if ($params === null) {
			return [
				MariaStanError::buildPHPSTanRuleError(
					"Dynamic SQL: unable to recover analyser result for mysqli_stmt::{$methodName}() call. Got "
					. $callerType->describe(VerbosityLevel::precise()),
					MariaStanErrorIdentifiers::DYNAMIC_SQL,
				),
			];
		}

		$executeParamTypes = $this->phpstanMysqliHelper->getExecuteParamTypesFromArgument(
			$scope,
			$node->getArgs()[0] ?? null,
		);

		return MariaStanError::arrayToPHPStanRuleErrors(
			$this->phpstanMysqliHelper->execute($params, $executeParamTypes),
		);
	}
}
