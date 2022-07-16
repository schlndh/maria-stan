<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi\data;

use MariaStan\DatabaseTestCase;
use MariaStan\Util\MariaDbErrorCodes;
use mysqli;
use mysqli_sql_exception;

use function MariaStan\Testing\assertFirstArgumentErrors;

class MySQLiRuleInvalidDataTest extends DatabaseTestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$db = self::getDefaultSharedConnection();
		self::initData($db);
	}

	public static function initData(mysqli $db): void
	{
		$tableName = 'mysqli_rule_invalid';
		self::doInitDb($db, $tableName);
	}

	private static function doInitDb(mysqli $db, string $tableName): void
	{
		// Hide $tableName from phpstan so that it doesn't analyze these queries
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NOT NULL,
				name VARCHAR(255) NULL
			);
		");
		$db->query("INSERT INTO {$tableName} (id, name) VALUES (1, 'aa'), (2, NULL)");
	}

	public function test(): void
	{
		$db = self::getDefaultSharedConnection();

		try {
			assertFirstArgumentErrors(
				$db->query('SELECT missing'),
				'Unknown column missing',
			);
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::ER_BAD_FIELD_ERROR, $e->getCode());
		}
	}
}
