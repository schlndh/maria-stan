<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi\data;

use MariaStan\TestCaseHelper;
use mysqli;
use PHPUnit\Framework\TestCase;

use function rand;

use const PHP_VERSION_ID;

class MySQLiRuleValidDataTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$db = TestCaseHelper::getDefaultSharedConnection();
		self::initData($db);
	}

	public static function initData(mysqli $db): void
	{
		$tableName = 'mysqli_rule_valid';
		self::doInitDb($db, $tableName);
	}

	private static function doInitDb(mysqli $db, string $tableName): void
	{
		// Hide $tableName from phpstan so that it doesn't analyze these queries
		// @phpstan-ignore-next-line
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NOT NULL,
				name VARCHAR(255) NULL
			);
		");
		// @phpstan-ignore-next-line
		$db->query("INSERT INTO {$tableName} (id, name) VALUES (1, 'aa'), (2, NULL)");
	}

	public function testValid(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();

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

		$params = rand()
			? [0, 1]
			: ['a', 'b'];

		$stmt = $db->prepare('SELECT ?, ?');
		$stmt->execute($params);
		$stmt->close();

		if (PHP_VERSION_ID >= 80200) {
			$result = $db->execute_query('SELECT ?', [1]);
			$result->close();
		}

		// Make phpunit happy. I just care that it doesn't throw an exception and that phpstan doesn't report errors.
		$this->assertTrue(true);
	}

	public function testDynamicSql(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();

		$db->query('SELECT ' . ($this->hideValueFromPhpstan(true) ? '1' : '2') . ' WHERE 1');

		$condition = $this->hideValueFromPhpstan(true);
		$stmt = $db->prepare($condition ? 'SELECT ?' : 'SELECT ?, ?');
		$stmt->execute($condition ? [1] : [1, 2]);

		// Make phpunit happy. I just care that it doesn't throw an exception and that phpstan doesn't report errors.
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
