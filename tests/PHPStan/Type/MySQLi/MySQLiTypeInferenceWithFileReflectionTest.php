<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\DatabaseTestCaseHelper;
use MariaStan\DbReflection\MariaDbFileDbReflection;
use MariaStan\PHPStan\Type\MySQLi\data\MySQLiTypeInferenceDataTest;
use MariaStan\Util\MysqliUtil;
use PHPStan\Testing\TypeInferenceTestCase;

use function file_put_contents;

class MySQLiTypeInferenceWithFileReflectionTest extends TypeInferenceTestCase
{
	/** @return iterable<mixed> */
	public function dataFileAsserts(): iterable
	{
		$mysqli = DatabaseTestCaseHelper::getDefaultSharedConnection();
		MySQLiTypeInferenceDataTest::initData($mysqli);
		file_put_contents(
			__DIR__ . '/schema.dump',
			MariaDbFileDbReflection::dumpSchema($mysqli, MysqliUtil::getDatabaseName($mysqli)),
		);

		// path to a file with actual asserts of expected types:
		yield from $this->gatherAssertTypes(__DIR__ . '/data/MySQLiTypeInferenceDataTest.php');
	}

	/** @dataProvider dataFileAsserts */
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
		];
	}
}
