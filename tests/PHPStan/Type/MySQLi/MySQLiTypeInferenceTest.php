<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\PHPStan\Type\MySQLi\data\MySQLiTypeInferenceDataTest;
use MariaStan\TestCaseHelper;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use const PHP_VERSION_ID;

class MySQLiTypeInferenceTest extends TypeInferenceTestCase
{
	/** @return list<string> */
	public static function getTestFiles(): array
	{
		$result = [__DIR__ . '/data/MySQLiTypeInferenceDataTest.php'];

		if (PHP_VERSION_ID >= 80200) {
			$result[] = __DIR__ . '/data/MySQLiExecuteQueryTypeInferenceDataTest.php';
		}

		return $result;
	}

	/** @return iterable<mixed> */
	public static function dataFileAsserts(): iterable
	{
		$mysqli = TestCaseHelper::getDefaultSharedConnection();
		MySQLiTypeInferenceDataTest::initData($mysqli);

		foreach (self::getTestFiles() as $testFile) {
			yield from self::gatherAssertTypes($testFile);
		}
	}

	#[DataProvider('dataFileAsserts')]
	public function testFileAsserts(string $assertType, string $file, mixed ...$args): void
	{
		$this->assertFileAsserts($assertType, $file, ...$args);
	}

	/** @return array<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../../../extension.mysqli.neon',
			__DIR__ . '/../../test.neon',
			__DIR__ . '/test.column-type-overrides.neon',
		];
	}
}
