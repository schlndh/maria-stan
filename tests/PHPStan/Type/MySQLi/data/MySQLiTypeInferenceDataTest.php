<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi\data;

use MariaStan\DatabaseTestCase;
use mysqli;

use function array_keys;
use function function_exists;
use function get_debug_type;
use function in_array;
use function PHPStan\Testing\assertType;

use const MYSQLI_ASSOC;

class MySQLiTypeInferenceDataTest extends DatabaseTestCase
{
	public static function initData(mysqli $db): void
	{
		$db->query('
			CREATE OR REPLACE TABLE mysqli_test (
				id INT NOT NULL,
				name VARCHAR(255) NULL
			);
		');
		$db->query('INSERT INTO mysqli_test (id, name) VALUES (1, "aa"), (2, NULL)');
	}

	public function test(): void
	{
		$db = $this->getDefaultSharedConnection();
		self::initData($db);
		$rows = $db->query('
			SELECT * FROM mysqli_test
		')->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('*ERROR*', $row['doesnt_exist']);
			}

			$this->assertEqualsCanonicalizing(['id', 'name'], array_keys($row));
			$this->assertSame('int', get_debug_type($row['id']));
			$this->assertTrue(
				in_array(get_debug_type($row['name']), ['string', 'null'], true),
				get_debug_type($row['name']),
			);
		}
	}
}
