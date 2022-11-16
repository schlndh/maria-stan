<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi\data;

use MariaStan\DatabaseTestCaseHelper;
use MariaStan\PHPStan\MySQLiWrapper;
use MariaStan\Util\MariaDbErrorCodes;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;

class MySQLiWrapperRuleInvalidDataTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		self::initData($db);
	}

	public static function initData(mysqli $db): void
	{
		$tableName = 'mysqli_wrapper_rule_invalid';
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
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$wrapper = new MySQLiWrapper($db);

		try {
			$wrapper->insert('missing_table', ['aaa' => 5]);
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::ER_NO_SUCH_TABLE, $e->getCode());
		}

		$wrapper = new MySQLiWrapper($db);

		try {
			$wrapper->insert('mysqli_wrapper_rule_invalid', ['name' => 'asdasd']);
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD, $e->getCode());
		}

		try {
			$this->doInsert($wrapper, 'asdasd');
			$this->fail('Exception expected');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame(MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD, $e->getCode());
		}
	}

	private function doInsert(MySQLiWrapper $wrapper, string $name): void
	{
		// Make sure the extension works even if the values are not known statically.
		$wrapper->insert('mysqli_wrapper_rule_invalid', ['name' => $name]);
	}

	public function testDynamicSql(): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$wrapper = new MySQLiWrapper($db);
		$wrapper->insert($this->hideValueFromPhpstan('mysqli_wrapper_rule_invalid'), ['id' => 97987]);
		$wrapper->insert('mysqli_wrapper_rule_invalid', [$this->hideValueFromPhpstan('id') => 64645]);

		// Make phpunit happy.
		$this->assertTrue(true);
	}

	/**
	 * @template T
	 * @param T $value
	 * @return T
	 */
	private function hideValueFromPhpstan(mixed $value): mixed
	{
		return $value;
	}
}
