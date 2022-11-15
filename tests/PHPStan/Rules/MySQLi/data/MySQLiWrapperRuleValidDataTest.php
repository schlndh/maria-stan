<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi\data;

use MariaStan\DatabaseTestCaseHelper;
use MariaStan\PHPStan\MySQLiWrapper;
use mysqli;
use PHPUnit\Framework\TestCase;

class MySQLiWrapperRuleValidDataTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		self::initData($db);
	}

	public static function initData(mysqli $db): void
	{
		$tableName = 'mysqli_wrapper_rule_valid';
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
		$wrapper = new MySQLiWrapper($db);
		$wrapper->insert('mysqli_wrapper_rule_valid', ['id' => 98797]);
		$wrapper->insert('mysqli_wrapper_rule_valid', ['id' => 98798, 'name' => 'qeasdas']);
		$this->doInsert($wrapper, 99999, 'asdasd');

		// Make phpunit happy. I just care that it doesn't throw an exception and that phpstan doesn't report errors.
		$this->assertTrue(true);
	}

	private function doInsert(MySQLiWrapper $wrapper, int $id, string $name): void
	{
		// Make sure the extension works even if the values are not known statically.
		$wrapper->insert('mysqli_wrapper_rule_valid', ['id' => $id, 'name' => $name]);
	}
}
