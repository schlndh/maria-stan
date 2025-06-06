<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules;

use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\AnalyserError;
use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\DbReflection\DbReflection;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\PHPStan\Helper\MariaStanError;
use MariaStan\PHPStan\Helper\MariaStanErrorIdentifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

use function array_keys;
use function array_map;
use function array_merge;
use function assert;
use function count;
use function in_array;
use function reset;
use function sprintf;

/** @implements Rule<FuncCall> */
class CheckViewRule implements Rule
{
	public function __construct(private readonly DbReflection $dbReflection, private readonly Analyser $analyser)
	{
	}

	public function getNodeType(): string
	{
		return FuncCall::class;
	}

	/** @inheritDoc */
	public function processNode(Node $node, Scope $scope): array
	{
		assert($node instanceof FuncCall);

		if ($node->name instanceof Node\Name) {
			$funcName = $node->name->name;
		} else {
			$funcNameType = $scope->getType($node->name);
			$funcNameConstantStrings = $funcNameType->getConstantStrings();

			if (count($funcNameConstantStrings) !== 1) {
				return [];
			}

			$funcName = reset($funcNameConstantStrings)->getValue();
		}

		if ($funcName === 'MariaStan\\PHPStan\\checkView') {
			return $this->processCheckView($node->getArgs(), $scope);
		}

		if ($funcName !== 'MariaStan\\PHPStan\\checkAllViews') {
			return [];
		}

		try {
			$viewDefinitions = $this->dbReflection->getViewDefinitions();
		} catch (DbReflectionException $e) {
			return [
				MariaStanError::buildPHPSTanRuleError(
					"Failed to load view definitions: {$e->getMessage()}",
					MariaStanErrorIdentifiers::DB_REFLECTION_ERROR,
				),
			];
		}

		$errors = [];

		foreach ($viewDefinitions as $database => $views) {
			if (in_array($database, ['information_schema', 'mysql', 'performance_schema', 'sys'], true)) {
				continue;
			}

			foreach (array_keys($views) as $view) {
				$errors = array_merge($errors, $this->checkView($view, $database));
			}
		}

		return $errors;
	}

	/**
	 * @param array<Node\Arg> $args
	 * @return list<IdentifierRuleError>
	 */
	private function processCheckView(array $args, Scope $scope): array
	{
		if (count($args) === 0) {
			return [];
		}

		$viewName = $scope->getType($args[0]->value)->getConstantStrings();
		$dbName = [null];

		if (count($args) === 2) {
			$dbName = $scope->getType($args[1]->value)->getConstantScalarValues();
		}

		if (count($dbName) !== 1 || count($viewName) !== 1) {
			return [];
		}

		$viewName = $viewName[0]->getValue();
		$dbName = $dbName[0];

		if ($dbName !== null) {
			$dbName = (string) $dbName;
		}

		return $this->checkView($viewName, $dbName);
	}

	/** @return list<IdentifierRuleError> */
	private function checkView(string $view, ?string $dbName): array
	{
		try {
			$viewDefinition = $this->dbReflection->findViewDefinition($view, $dbName);
			$analyserResult = $this->analyser->analyzeQuery($viewDefinition);
		} catch (DbReflectionException | AnalyserException $e) {
			return self::createPHPStanErrorsFromAnalyserErrors([$e->toAnalyserError()], $view, $dbName);
		}

		return self::createPHPStanErrorsFromAnalyserErrors($analyserResult->errors, $view, $dbName);
	}

	/**
	 * @param list<AnalyserError> $errors
	 * @return list<IdentifierRuleError>
	 */
	private static function createPHPStanErrorsFromAnalyserErrors(array $errors, string $view, ?string $dbName): array
	{
		$mariaStanErrors = array_map(static fn (AnalyserError $e) => new MariaStanError(
			sprintf(
				'Error in view \'%s\': %s',
				$dbName !== null
					? "{$dbName}.{$view}"
					: $view,
				$e->message,
			),
			$e->type->value,
		), $errors);

		return MariaStanError::arrayToPHPStanRuleErrors($mariaStanErrors);
	}
}
