<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Helper\MySQLi\PHPStanMySQLiHelper;
use MariaStan\PHPStan\MySQLiWrapper;
use MariaStan\Util\MysqliUtil;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;

use function assert;
use function count;
use function implode;

/**
 * Example extension for insert shortcut
 *
 * @implements Rule<MethodCall>
 */
class MySQLiWrapperRule implements Rule
{
	public function __construct(private readonly PHPStanMySQLiHelper $phpstanMysqliHelper)
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

		if (! $objectType instanceof ObjectType || $objectType->getClassName() !== MySQLiWrapper::class) {
			return [];
		}

		if ($methodName !== 'insert') {
			return [];
		}

		$args = $node->getArgs();

		if (count($args) !== 2) {
			return [];
		}

		$tableType = $scope->getType($args[0]->value);
		$dataType = $scope->getType($args[1]->value);

		if (! $tableType instanceof ConstantStringType) {
			return [
				"Dynamic SQL: expected table as constant string, got: "
					. $tableType->describe(VerbosityLevel::precise()),
			];
		}

		if (! $dataType instanceof ConstantArrayType) {
			return [
				"Dynamic SQL: expected data as constant array, got: "
				. $dataType->describe(VerbosityLevel::precise()),
			];
		}

		$tableQuoted = MysqliUtil::quoteIdentifier($tableType->getValue());
		$sql = "INSERT INTO {$tableQuoted} SET ";
		$sets = [];

		foreach ($dataType->getKeyTypes() as $col) {
			if (! $col instanceof ConstantStringType) {
				return [];
			}

			$sets[] = MysqliUtil::quoteIdentifier($col->getValue()) . ' = ?';
		}

		$sql .= implode(', ', $sets);
		$queryType = new ConstantStringType($sql);
		$result = $this->phpstanMysqliHelper->prepare($queryType);

		return $result->errors;
	}
}
