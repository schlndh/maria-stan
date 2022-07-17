<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules;

use PHPStan\Testing\RuleTestCase;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\VerbosityLevel;

use function count;

/**
 * Copied from {@see TypeInferenceTestCase}
 *
 * @template TRule of \PHPStan\Rules\Rule
 * @extends RuleTestCase<TRule>
 */
abstract class MariaStanRuleTestCase extends RuleTestCase
{
	/** @return array{string, array<array{string, int}>} [file, [error, line number]] */
	public function gatherAssertErrors(string $file): array
	{
		$asserts = [];
		// phpcs:disable SlevomatCodingStandard.Classes.ForbiddenPublicProperty.ForbiddenPublicProperty
		$typeInferenceTestCase = new class extends TypeInferenceTestCase {
			/** @var MariaStanRuleTestCase<TRule> $outerTestCase */
			public static MariaStanRuleTestCase $outerTestCase;

			public static function getAdditionalConfigFiles(): array
			{
				return self::$outerTestCase::getAdditionalConfigFiles();
			}
		};
		$typeInferenceTestCase::$outerTestCase = $this;
		$typeInferenceTestCase->processFile(
			$file,
			function (\PhpParser\Node $node, \PHPStan\Analyser\Scope $scope) use (&$asserts): void {
				if (!$node instanceof \PhpParser\Node\Expr\FuncCall) {
					return;
				}

				$nameNode = $node->name;

				if (!$nameNode instanceof \PhpParser\Node\Name) {
					return;
				}

				$functionName = $nameNode->toString();

				/** @see assertFirstArgumentErrors() */
				if ($functionName !== 'MariaStan\\Testing\\assertFirstArgumentErrors') {
					return;
				}

				$errorLine = $node->getArgs()[0]->getLine();

				for ($i = 1; $i < count($node->getArgs()); $i++) {
					$arg = $node->getArgs()[$i];
					$argType = $scope->getType($arg->value);

					if (! $argType instanceof ConstantStringType) {
						$this->fail(
							'Expected ConstantString type got: ' . $argType->describe(VerbosityLevel::precise()),
						);
					}

					$asserts[] = [$argType->getValue(), $errorLine];
				}
			},
		);

		return [$file, $asserts];
	}
}
