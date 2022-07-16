<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\AnalyserError;
use MariaStan\Analyser\Exception\AnalyserException;
use mysqli;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Type\Constant\ConstantStringType;
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
		$methodName = '';

		if ($node->name instanceof Node\Identifier) {
			$methodName = $node->name->name;
		} else {
			$methodNameType = $scope->getType($node->name);

			if (! $methodNameType instanceof ConstantStringType) {
				return [];
			}

			$methodName = $methodNameType->getValue();
		}

		if ($methodName !== 'query' || count($node->getArgs()) === 0) {
			return [];
		}

		$objectType = $scope->getType($node->var);

		if (! $objectType instanceof ObjectType || $objectType->getClassName() !== mysqli::class) {
			return [];
		}

		$queryType = $scope->getType($node->getArgs()[0]->value);

		if (! $queryType instanceof ConstantStringType) {
			return [];
		}

		try {
			$analyserResult = $this->analyser->analyzeQuery($queryType->getValue());
		} catch (AnalyserException $e) {
			return [$e->getMessage()];
		}

		return array_map(static fn (AnalyserError $err) => $err->message, $analyserResult->errors);
	}
}
