<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\PHPStan\Type\MySQLi\data\MySQLiTypeInferenceDataTest;
use MariaStan\TestCaseHelper;
use PHPStan\Testing\TypeInferenceTestCase;

class MySQLiTypeInferenceTest extends TypeInferenceTestCase
{
	/** @return iterable<mixed> */
	public function dataFileAsserts(): iterable
	{
		$mysqli = TestCaseHelper::getDefaultSharedConnection();
		MySQLiTypeInferenceDataTest::initData($mysqli);

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
			__DIR__ . '/../../test.neon',
			__DIR__ . '/test.column-type-overrides.neon',
		];
	}
}
