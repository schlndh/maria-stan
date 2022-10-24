<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Rules\MySQLi\data\MySQLiRuleInvalidDataTest;
use MariaStan\Testing\MariaStanRuleTestCase;
use mysqli;
use PHPStan\Rules\Rule;

use function array_map;
use function assert;
use function basename;
use function file_get_contents;
use function implode;
use function MariaStan\fileNamesInDir;
use function preg_last_error_msg;
use function preg_replace;
use function str_contains;
use function trim;

/** @extends MariaStanRuleTestCase<MySQLiRule> */
class MySQLiRuleTest extends MariaStanRuleTestCase
{
	public static function getErrorsFileForPhpFile(string $phpFileName): string
	{
		return preg_replace('/\.php$/', '.errors', $phpFileName)
			?? throw new \RuntimeException(preg_last_error_msg());
	}

	protected function getRule(): Rule
	{
		return self::getContainer()->getByType(MySQLiRule::class);
	}

	public function getTestOutput(string $file): string
	{
		$mysqli = self::getContainer()->getService('mariaStanDb');
		assert($mysqli instanceof mysqli);
		MySQLiRuleInvalidDataTest::initData($mysqli);

		$errors = $this->gatherAnalyserErrors([$file]);

		return implode("\n", array_map($this->formatPHPStanError(...), $errors)) . "\n";
	}

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestData(): iterable
	{
		foreach (fileNamesInDir(__DIR__ . '/data', 'php') as $fileName) {
			$errors = file_get_contents(self::getErrorsFileForPhpFile($fileName));

			yield basename($fileName) => [
				'file' => $fileName,
				'expected output' => $errors,
			];
		}
	}

	/** @dataProvider provideTestData */
	public function test(string $file, string $expectedOutput): void
	{
		$output = $this->getTestOutput($file);
		$this->assertSame($expectedOutput, $output);

		match (true) {
			str_contains($file, 'Valid') => $this->assertSame('', trim($output)),
			str_contains($file, 'Invalid') => $this->assertNotSame('', trim($output)),
			default => null,
		};
	}

	/** @return array<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../../../extension.mysqli.neon',
			__DIR__ . '/../../test.neon',
		];
	}
}
