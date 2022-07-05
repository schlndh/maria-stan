<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use mysqli;
use PHPStan\Testing\TypeInferenceTestCase;

use function assert;

class MySQLiTypeInferenceTest extends TypeInferenceTestCase
{
	/** @return iterable<mixed> */
	public function dataFileAsserts(): iterable
	{
		$mysqli = self::getContainer()->getService('mariaStanDb');
		assert($mysqli instanceof mysqli);
		$mysqli->query('
			CREATE OR REPLACE TABLE mysqli_test (
				id INT NOT NULL,
				name VARCHAR(255) NULL
			);
		');

		// path to a file with actual asserts of expected types:
		yield from $this->gatherAssertTypes(__DIR__ . '/data/mysqli.php');
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
			__DIR__ . '/../../../../extension.neon',
			__DIR__ . '/../../test.neon',
		];
	}
}
