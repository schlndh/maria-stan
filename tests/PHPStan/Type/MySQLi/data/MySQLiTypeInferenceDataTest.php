<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi\data;

use MariaStan\DatabaseTestCase;
use mysqli;
use Nette\Schema\Expect;
use Nette\Schema\Processor;

use function array_column;
use function array_key_exists;
use function array_keys;
use function function_exists;
use function gettype;
use function implode;
use function in_array;
use function is_string;
use function PHPStan\Testing\assertType;

use const MYSQLI_ASSOC;
use const MYSQLI_BOTH;
use const MYSQLI_NUM;

class MySQLiTypeInferenceDataTest extends DatabaseTestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$db = self::getDefaultSharedConnection();
		self::initData($db);
	}

	public static function initData(mysqli $db): void
	{
		$db->query('
			CREATE OR REPLACE TABLE mysqli_test (
				id INT NOT NULL,
				name VARCHAR(255) NULL,
				price DECIMAL(10, 2) NOT NULL
			);
		');
		$db->query('INSERT INTO mysqli_test (id, name, price) VALUES (1, "aa", 111.11), (2, NULL, 222.22)');

		$dataTypesTable = 'mysqli_test_data_types';
		$db->query("
			CREATE OR REPLACE TABLE {$dataTypesTable} (
				col_int INT NOT NULL,
				col_varchar_null VARCHAR(255) NULL,
				col_decimal DECIMAL(10, 2) NOT NULL,
				col_float FLOAT NOT NULL,
				col_double DOUBLE NOT NULL,
				col_datetime DATETIME NOT NULL
			);
		");
		$db->query("
			INSERT INTO {$dataTypesTable} (col_int, col_varchar_null, col_decimal, col_float, col_double, col_datetime)
			VALUES (1, 'aa', 111.11, 11.11, 1.1, NOW()), (2, NULL, 222.22, 22.22, 2.2, NOW())
		");
	}

	public function testAssoc(): void
	{
		$db = $this->getDefaultSharedConnection();
		$rows = $db->query('
			SELECT * FROM mysqli_test
		')->fetch_all(MYSQLI_ASSOC);
		$schemaProcessor = new Processor();
		$schema = Expect::structure([
			'id' => Expect::int(),
			'name' => Expect::anyOf(Expect::string(), Expect::null()),
			'price' => Expect::anyOf(Expect::string(), Expect::null()),
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists('id', $row));
				assertType('true', array_key_exists('name', $row));
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row['price']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'name', 'price'], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}

		$rows = $db->query('
			SELECT *, name, price, id FROM mysqli_test
		')->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists('id', $row));
				assertType('true', array_key_exists('name', $row));
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row['price']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'name', 'price'], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}
	}

	public function testNum(): void
	{
		$db = $this->getDefaultSharedConnection();
		$rows = $db->query('
			SELECT * FROM mysqli_test
		')->fetch_all(MYSQLI_NUM);
		$schemaProcessor = new Processor();
		$schema = Expect::structure([
			'0' => Expect::int(),
			'1' => Expect::anyOf(Expect::string(), Expect::null()),
			'2' => Expect::string(),
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists(0, $row));
				assertType('true', array_key_exists(1, $row));
				assertType('int', $row[0]);
				assertType('string|null', $row[1]);
				assertType('numeric-string', $row[2]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1, 2], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}

		$rows = $db->query('
			SELECT * FROM mysqli_test
		')->fetch_all();

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists(0, $row));
				assertType('true', array_key_exists(1, $row));
				assertType('int', $row[0]);
				assertType('string|null', $row[1]);
				assertType('numeric-string', $row[2]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1, 2], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}

		$rows = $db->query('
			SELECT *, name, id, price FROM mysqli_test
		')->fetch_all(MYSQLI_NUM);
		$schemaProcessor = new Processor();
		$schema = Expect::structure([
			'0' => Expect::int(),
			'1' => Expect::anyOf(Expect::string(), Expect::null()),
			'2' => Expect::string(),
			'3' => Expect::anyOf(Expect::string(), Expect::null()),
			'4' => Expect::int(),
			'5' => Expect::string(),
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists(0, $row));
				assertType('true', array_key_exists(1, $row));
				assertType('true', array_key_exists(2, $row));
				assertType('true', array_key_exists(3, $row));
				assertType('true', array_key_exists(4, $row));
				assertType('true', array_key_exists(5, $row));
				assertType('int', $row[0]);
				assertType('string|null', $row[1]);
				assertType('numeric-string', $row[2]);
				assertType('string|null', $row[3]);
				assertType('int', $row[4]);
				assertType('numeric-string', $row[5]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1, 2, 3, 4, 5], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}
	}

	public function testBoth(): void
	{
		$db = $this->getDefaultSharedConnection();
		$rows = $db->query('
			SELECT * FROM mysqli_test
		')->fetch_all(MYSQLI_BOTH);
		$schemaProcessor = new Processor();
		$schema = Expect::structure([
			'0' => Expect::int(),
			'id' => Expect::int(),
			'1' => Expect::anyOf(Expect::string(), Expect::null()),
			'name' => Expect::anyOf(Expect::string(), Expect::null()),
			'2' => Expect::string(),
			'price' => Expect::string(),
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists('id', $row));
				assertType('true', array_key_exists('name', $row));
				assertType('true', array_key_exists('price', $row));
				assertType('true', array_key_exists(0, $row));
				assertType('true', array_key_exists(1, $row));
				assertType('true', array_key_exists(2, $row));
				assertType('int', $row[0]);
				assertType('int', $row['id']);
				assertType('string|null', $row[1]);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row[2]);
				assertType('numeric-string', $row['price']);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['doesnt_exist']);
			}

			$this->assertSame([0, 'id', 1, 'name', 2, 'price'], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}

		$rows = $db->query('
			SELECT *, name, id FROM mysqli_test
		')->fetch_all(MYSQLI_BOTH);
		$schemaProcessor = new Processor();
		$schema = Expect::structure([
			'0' => Expect::int(),
			'id' => Expect::int(),
			'1' => Expect::anyOf(Expect::string(), Expect::null()),
			'name' => Expect::anyOf(Expect::string(), Expect::null()),
			'2' => Expect::string(),
			'price' => Expect::string(),
			'3' => Expect::anyOf(Expect::string(), Expect::null()),
			'4' => Expect::int(),
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists('id', $row));
				assertType('true', array_key_exists('name', $row));
				assertType('true', array_key_exists('price', $row));
				assertType('true', array_key_exists(0, $row));
				assertType('true', array_key_exists(1, $row));
				assertType('true', array_key_exists(2, $row));
				assertType('true', array_key_exists(3, $row));
				assertType('true', array_key_exists(4, $row));
				assertType('int', $row[0]);
				assertType('int', $row['id']);
				assertType('string|null', $row[1]);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row[2]);
				assertType('numeric-string', $row['price']);
				assertType('string|null', $row[3]);
				assertType('int', $row[4]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['doesnt_exist']);
			}

			$this->assertSame([0, 'id', 1, 'name', 2, 'price', 3, 4], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}
	}

	// This is not executed, it's just here as a data source for the PHPStan test.
	public function checkDynamicReturnType(int $returnType): void
	{
		$db = $this->getDefaultSharedConnection();
		$rows = $db->query('
			SELECT * FROM mysqli_test
		')->fetch_all($returnType);

		// We don't know which is used, so it should behave similarly to BOTH
		foreach ($rows as $row) {
			// These keys are all optional
			assertType('bool', array_key_exists('id', $row));
			assertType('bool', array_key_exists('name', $row));
			assertType('bool', array_key_exists('price', $row));
			assertType('bool', array_key_exists(0, $row));
			assertType('bool', array_key_exists(1, $row));
			assertType('bool', array_key_exists(2, $row));
			assertType('int', $row[0]);
			assertType('int', $row['id']);
			assertType('string|null', $row[1]);
			assertType('string|null', $row['name']);
			assertType('numeric-string', $row[2]);
			assertType('numeric-string', $row['price']);
			assertType('*ERROR*', $row[10]);
			assertType('*ERROR*', $row['doesnt_exist']);
		}

		$rows = $db->query('
			SELECT *, name, id FROM mysqli_test
		')->fetch_all($returnType);

		// We don't know which is used, so it should behave similarly to BOTH
		foreach ($rows as $row) {
			// These keys are all optional
			assertType('bool', array_key_exists('id', $row));
			assertType('bool', array_key_exists('name', $row));
			assertType('bool', array_key_exists('price', $row));
			assertType('bool', array_key_exists(0, $row));
			assertType('bool', array_key_exists(1, $row));
			assertType('bool', array_key_exists(2, $row));
			assertType('bool', array_key_exists(3, $row));
			assertType('bool', array_key_exists(4, $row));
			assertType('int', $row[0]);
			assertType('int', $row['id']);
			assertType('string|null', $row[1]);
			assertType('string|null', $row['name']);
			assertType('string|null', $row[3]);
			assertType('numeric-string', $row['price']);
			assertType('numeric-string', $row[2]);
			assertType('int', $row[4]);
			assertType('*ERROR*', $row[10]);
			assertType('*ERROR*', $row['doesnt_exist']);
		}
	}

	public function testDataTypes(): void
	{
		// TODO: switch to fetch_column once a type-specifying extension is made for it.
		$db = $this->getDefaultSharedConnection();
		$rows = $db->query('
			SELECT col_int FROM mysqli_test_data_types
		')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('int', $value);
			}

			$this->assertGettype('integer', $value);
		}

		$rows = $db->query('
			SELECT col_varchar_null FROM mysqli_test_data_types
		')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('string|null', $value);
			}

			$this->assertGettype(['string', 'NULL'], $value);
		}

		$rows = $db->query('
			SELECT col_decimal FROM mysqli_test_data_types
		')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('numeric-string', $value);
			}

			$this->assertGettype('string', $value);
		}

		$rows = $db->query('
			SELECT col_float FROM mysqli_test_data_types
		')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('float', $value);
			}

			$this->assertGettype('double', $value);
		}

		$rows = $db->query('
			SELECT col_double FROM mysqli_test_data_types
		')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('float', $value);
			}

			$this->assertGettype('double', $value);
		}

		$rows = $db->query('
			SELECT col_datetime FROM mysqli_test_data_types
		')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('string', $value);
			}

			$this->assertGettype('string', $value);
		}
	}

	/** @param string|array<string> $allowedTypes */
	private function assertGettype(string|array $allowedTypes, mixed $value): void
	{
		$type = gettype($value);

		if (is_string($allowedTypes)) {
			$allowedTypes = [$allowedTypes];
		}

		$message = "Failed asserting that '{$type}' is in [" . implode(', ', $allowedTypes) . ']';
		$this->assertTrue(in_array($type, $allowedTypes, true), $message);
	}
}
