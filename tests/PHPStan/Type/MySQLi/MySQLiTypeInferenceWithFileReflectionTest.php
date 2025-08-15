<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\DbReflection\MariaDbFileDbReflection;
use MariaStan\PHPStan\Type\MySQLi\data\MySQLiTypeInferenceDataTest;
use MariaStan\TestCaseHelper;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function file_put_contents;

class MySQLiTypeInferenceWithFileReflectionTest extends TypeInferenceTestCase
{
	/** @return iterable<mixed> */
	public static function dataFileAsserts(): iterable
	{
		$mysqli = TestCaseHelper::getDefaultSharedConnection();
		MySQLiTypeInferenceDataTest::initData($mysqli);
		file_put_contents(
			__DIR__ . '/schema.dump',
			MariaDbFileDbReflection::dumpSchema(
				$mysqli,
				[TestCaseHelper::getDefaultDbName(), TestCaseHelper::getSecondDbName()],
			),
		);

		foreach (MySQLiTypeInferenceTest::getTestFiles() as $testFile) {
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
			__DIR__ . '/test.file-reflection.neon',
			__DIR__ . '/test.column-type-overrides.neon',
		];
	}
}
