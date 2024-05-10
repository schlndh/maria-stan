<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Helper\MariaStanError;
use MariaStan\PHPStan\Helper\MySQLi\PHPStanMySQLiHelper;
use MariaStan\PHPStan\MySQLiWrapper;
use MariaStan\Util\MysqliUtil;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\VerbosityLevel;

use function assert;
use function count;
use function implode;
use function reset;

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

		if (count($objectClassNames) !== 1 || $objectClassNames[0] !== MySQLiWrapper::class) {
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
		$tableConstantStrings = $tableType->getConstantStrings();

		if (count($tableConstantStrings) !== 1) {
			return [
				"Dynamic SQL: expected table as constant string, got: "
					. $tableType->describe(VerbosityLevel::precise()),
			];
		}

		$dataType = $scope->getType($args[1]->value);
		$dataConstantArrays = $dataType->getConstantArrays();

		if (count($dataConstantArrays) !== 1) {
			return [
				"Dynamic SQL: expected data as constant array, got: "
				. $dataType->describe(VerbosityLevel::precise()),
			];
		}

		$tableQuoted = MysqliUtil::quoteIdentifier(reset($tableConstantStrings)->getValue());
		$sql = "INSERT INTO {$tableQuoted} SET ";
		$sets = [];

		foreach (reset($dataConstantArrays)->getKeyTypes() as $col) {
			assert($col instanceof \PHPStan\Type\Type);
			$colConstantStrings = $col->getConstantStrings();

			if (count($colConstantStrings) !== 1) {
				return [];
			}

			$sets[] = MysqliUtil::quoteIdentifier(reset($colConstantStrings)->getValue()) . ' = ?';
		}

		$sql .= implode(', ', $sets);
		$queryType = new ConstantStringType($sql);
		$result = $this->phpstanMysqliHelper->prepare($queryType);

		return MariaStanError::arrayToPHPStanRuleErrors($result->errors);
	}
}
