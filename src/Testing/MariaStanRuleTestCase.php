<?php

declare(strict_types=1);

namespace MariaStan\Testing;

use PHPStan\Analyser\Error;
use PHPStan\Testing\RuleTestCase;

/**
 * @template TRule of \PHPStan\Rules\Rule
 * @extends RuleTestCase<TRule>
 */
abstract class MariaStanRuleTestCase extends RuleTestCase
{
	public function formatPHPStanError(Error $error): string
	{
		/** Copied from {@see RuleTestCase} */
		$message = $error->getMessage();
		$line = $error->getLine() ?? -1;
		$tip = $error->getTip();
		$message = \sprintf('%02d: %s', $line, $message);

		if ($tip !== null) {
			$message .= "\n    💡 " . $tip;
		}

		return $message;
	}
}
