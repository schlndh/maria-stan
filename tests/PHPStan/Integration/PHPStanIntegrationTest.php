<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Integration;

use MariaStan\TestCaseHelper;
use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Error;
use PHPStan\Testing\PHPStanTestCase;

use function array_map;
use function implode;
use function sprintf;

// phpcs:disable Generic.Files.LineLength.TooLong
class PHPStanIntegrationTest extends PHPStanTestCase
{
	public function testMySQLiTypeNodeResolverExtension(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query('
			CREATE OR REPLACE TABLE mysqli_type_node_test (
				id INT NOT NULL,
				name VARCHAR(255) NULL,
				price DECIMAL(10, 2) NOT NULL
			);
		');
		$db->query('INSERT INTO mysqli_type_node_test (id, name, price) VALUES (1, "aa", 111.11), (2, NULL, 222.22)');

		$db->query('
			CREATE OR REPLACE TABLE mysqli_type_node_test_2 (
				id INT NOT NULL,
				foo VARCHAR(255) NOT NULL
			);
		');
		$db->query('INSERT INTO mysqli_type_node_test_2 (id, foo) VALUES (1, "aa")');
		$errors = $this->runAnalyse(__DIR__ . '/data/MySQLiTypeNodeResolverData.php');
		$this->assertAnalyserErrors([
			[
				'Method MariaStan\PHPStan\Integration\data\MySQLiTypeNodeResolverData::wrongReturn() should return array{id: int, name: string|null, price: numeric-string} but returns array{1: int}.',
				73,
				"Array does not have offset 'id'.",
			],
		], $errors);
	}

	/**
	 * @param array<string>|null $allAnalysedFiles
	 * @return array<Error>
	 */
	private function runAnalyse(string $file, ?array $allAnalysedFiles = null): array
	{
		// Taken from https://github.com/phpstan/phpstan-src/blob/27e2b53621d0cb19ef410a28b418674e399085f1/tests/PHPStan/Analyser/AnalyserIntegrationTest.php#L1324
		$file = $this->getFileHelper()->normalizePath($file);

		$analyser = self::getContainer()->getByType(Analyser::class);
		$errors = $analyser->analyse([$file], null, null, true, $allAnalysedFiles)->getErrors();

		foreach ($errors as $error) {
			$this->assertSame($file, $error->getFilePath());
		}

		return $errors;
	}

	/**
	 * @param array<array{0: string, 1: int, 2?: string}> $expectedErrors
	 * @param array<Error> $actualErrors
	 * @return void
	 */
	private function assertAnalyserErrors(array $expectedErrors, array $actualErrors): void
	{
		// Taken from https://github.com/phpstan/phpstan-src/blob/27e2b53621d0cb19ef410a28b418674e399085f1/src/Testing/RuleTestCase.php#L135
		$strictlyTypedSprintf = static function (int $line, string $message, ?string $tip): string {
			$message = sprintf('%02d: %s', $line, $message);

			if ($tip !== null) {
				$message .= "\n    💡 " . $tip;
			}

			return $message;
		};

		$expectedErrors = array_map(
			static fn (array $error): string => $strictlyTypedSprintf($error[1], $error[0], $error[2] ?? null),
			$expectedErrors,
		);

		$actualErrors = array_map(
			static function (Error $error) use ($strictlyTypedSprintf): string {
				$line = $error->getLine();

				if ($line === null) {
					return $strictlyTypedSprintf(-1, $error->getMessage(), $error->getTip());
				}

				return $strictlyTypedSprintf($line, $error->getMessage(), $error->getTip());
			},
			$actualErrors,
		);

		$this->assertSame(implode("\n", $expectedErrors) . "\n", implode("\n", $actualErrors) . "\n");
	}

	/** @return array<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../../extension.mysqli.neon',
			__DIR__ . '/../test.neon',
			//__DIR__ . '/test.column-type-overrides.neon',
		];
	}
}
