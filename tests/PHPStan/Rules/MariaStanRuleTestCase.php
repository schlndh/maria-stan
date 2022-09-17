<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules;

use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Error;
use PHPStan\Testing\RuleTestCase;

use function assert;

/**
 * @template TRule of \PHPStan\Rules\Rule
 * @extends RuleTestCase<TRule>
 */
abstract class MariaStanRuleTestCase extends RuleTestCase
{
	/**
	 * @param array<string> $files
	 * @return array<Error>
	 */
	public function getAnalyserErrors(array $files): array
	{
		/** Copied from {@see RuleTestCase} */
		$files = \array_map([$this->getFileHelper(), 'normalizePath'], $files);
		$selfReflection = new \ReflectionClass($this);
		$getAnalyser = $selfReflection->getMethod('getAnalyser');

		$analyser = $getAnalyser->invoke($this);
		assert($analyser instanceof Analyser);
		$analyserResult = $analyser->analyse($files, null, null, true);

		if (\count($analyserResult->getInternalErrors()) > 0) {
			$this->fail(\implode("\n", $analyserResult->getInternalErrors()));
		}

		return $analyserResult->getUnorderedErrors();
	}

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
