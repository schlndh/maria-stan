<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules;

use MariaStan\TestCaseHelper;
use MariaStan\Testing\MariaStanRuleTestCase;
use mysqli;

use function array_map;
use function basename;
use function dirname;
use function file_get_contents;
use function implode;
use function preg_last_error_msg;
use function preg_replace;
use function str_contains;
use function str_replace;
use function trim;

/**
 * @template TRule of \PHPStan\Rules\Rule
 * @extends MariaStanRuleTestCase<TRule>
 */
abstract class BaseRuleTestCase extends MariaStanRuleTestCase
{
	abstract public static function getCurrentFile(): string;

	abstract public function initData(mysqli $db): void;

	public static function getErrorsFileForPhpFile(string $phpFileName): string
	{
		return preg_replace('/\.php$/', '.errors', $phpFileName)
			?? throw new \RuntimeException(preg_last_error_msg());
	}

	/** @return array<string> */
	public static function getTestData(string $testFile): array
	{
		$dataDir = dirname($testFile) . '/data/';
		$basename = basename($testFile);
		$prefix = str_replace('Test.php', '', $basename);

		return [$dataDir . $prefix . 'ValidDataTest.php', $dataDir . $prefix . 'InvalidDataTest.php'];
	}

	public function getTestOutput(string $file): string
	{
		$mysqli = TestCaseHelper::getDefaultSharedConnection();
		$this->initData($mysqli);
		$errors = $this->gatherAnalyserErrors([$file]);

		return implode("\n", array_map($this->formatPHPStanError(...), $errors)) . "\n";
	}

	/** @return array<string> */
	public static function getTestInputFiles(): array
	{
		return self::getTestData(static::getCurrentFile());
	}

	/** @return iterable<string, array<mixed>> name => args */
	public static function provideTestData(): iterable
	{
		foreach (self::getTestInputFiles() as $fileName) {
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
			__DIR__ . '/../../../extension.mysqli.neon',
			__DIR__ . '/../test.neon',
		];
	}
}
