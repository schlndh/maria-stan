<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi\data;

use MariaStan\DatabaseTestCaseHelper;
use MariaStan\Util\MariaDbErrorCodes;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;
use ValueError;

class MySQLiRuleInvalidDataTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
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

	public function testValid(): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$db->query('SELECT id FROM mysqli_rule_invalid');

		$stmt = $db->prepare('SELECT 1');
		$stmt->execute();
		$stmt->close();

		$stmt = $db->prepare('SELECT 1');
		$stmt->execute(null);
		$stmt->close();

		$stmt = $db->prepare('SELECT 1');
		$stmt->execute([]);
		$stmt->close();

		$stmt = $db->prepare('SELECT ?');
		$stmt->execute([1]);
		$stmt->close();

		// Make phpunit happy. I just care that it doesn't throw an exception and that phpstan doesn't report errors.
		$this->assertTrue(true);
	}

	public function testInvalid(): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();

		try {
			$db->query('SELECT missing');
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::ER_BAD_FIELD_ERROR, $e->getCode());
		}

		try {
			$db->query('SELECT ?');
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::ER_PARSE_ERROR, $e->getCode());
		}

		$stmt = $db->prepare('SELECT ?');

		try {
			$stmt->execute();
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::MYSQLI_NO_DATA_FOR_PREPARED_PARAMS, $e->getCode());
		}

		$stmt = $db->prepare('SELECT ?');

		try {
			$stmt->execute([]);
			$this->fail('Exception expected');
		} catch (ValueError) {
		}

		$stmt = $db->prepare('SELECT ?');

		try {
			$stmt->execute(null);
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::MYSQLI_NO_DATA_FOR_PREPARED_PARAMS, $e->getCode());
		}

		$stmt = $db->prepare('SELECT 1');

		try {
			$stmt->execute([1, 2, 3]);
			$this->fail('Exception expected');
		} catch (ValueError) {
		}

		try {
			$stmt = $db->prepare('asdlajkd qeosdasd ?');
			// Bug: this shouldn't complain about query needing 0 parameters.
			$stmt->execute([1]);
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::ER_PARSE_ERROR, $e->getCode());
		}
	}
}
