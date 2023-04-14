<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi\data;

use MariaStan\TestCaseHelper;
use mysqli;
use mysqli_result;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_key_exists;
use function array_keys;
use function assert;
use function function_exists;
use function gettype;
use function implode;
use function in_array;
use function is_string;
use function PHPStan\Testing\assertType;

use const MYSQLI_ASSOC;
use const MYSQLI_BOTH;
use const MYSQLI_NUM;

class MySQLiTypeInferenceDataTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$db = TestCaseHelper::getDefaultSharedConnection();
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
		$db = TestCaseHelper::getDefaultSharedConnection();
		$rows = $db->query('SELECT * FROM mysqli_test')->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{'id', 'name', 'price'}", array_keys($row));
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row['price']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'name', 'price'], array_keys($row));
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
		}

		$rows = $db->query('SELECT * FROM ' . $this->hideValueFromPhpstan('mysqli_test'))->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType("array<string, float|int|string|null>", $row);
			}

			$this->assertSame(['id', 'name', 'price'], array_keys($row));
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
		}

		$rows = $db->query('SELECT *, name, price, id FROM mysqli_test')->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{'id', 'name', 'price'}", array_keys($row));
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row['price']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'name', 'price'], array_keys($row));
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
		}

		$stmt = $db->prepare('SELECT * FROM mysqli_test');
		$stmt->execute();
		$result = $stmt->get_result();
		assert($result instanceof mysqli_result);
		$rows = $result->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{'id', 'name', 'price'}", array_keys($row));
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row['price']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'name', 'price'], array_keys($row));
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
		}

		$rows = $db->query('SELECT id, 5 val, "aa" id FROM mysqli_test')->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{'id', 'val'}", array_keys($row));
				assertType('string', $row['id']);
				assertType('int', $row['val']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'val'], array_keys($row));
			$this->assertIsString($row['id']);
			$this->assertIsInt($row['val']);
		}
	}

	public function testNum(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$rows = $db->query('SELECT * FROM mysqli_test')->fetch_all(MYSQLI_NUM);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 1, 2}", array_keys($row));
				assertType('int', $row[0]);
				assertType('string|null', $row[1]);
				assertType('numeric-string', $row[2]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1, 2], array_keys($row));
			$this->assertIsInt($row[0]);

			// bug: make sure that the ConstantArrayType has proper nextAutoIndexes to not trigger:
			// https://github.com/phpstan/phpstan-src/blob/9f53f4c840fe2d77438a776feaea87d2c5cf17e9/src/Type/Constant/ConstantArrayTypeBuilder.php#L146
			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);
		}

		$rows = $db->query('SELECT * FROM ' . $this->hideValueFromPhpstan('mysqli_test'))->fetch_all(MYSQLI_NUM);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType("array<int, float|int|string|null>", $row);
			}

			$this->assertSame([0, 1, 2], array_keys($row));
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);
		}

		$rows = $db->query('SELECT * FROM mysqli_test')->fetch_all();

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 1, 2}", array_keys($row));
				assertType('int', $row[0]);
				assertType('string|null', $row[1]);
				assertType('numeric-string', $row[2]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1, 2], array_keys($row));
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);
		}

		$stmt = $db->prepare('SELECT * FROM mysqli_test');
		$stmt->execute();
		$result = $stmt->get_result();
		assert($result instanceof mysqli_result);
		$rows = $result->fetch_all();

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 1, 2}", array_keys($row));
				assertType('int', $row[0]);
				assertType('string|null', $row[1]);
				assertType('numeric-string', $row[2]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1, 2], array_keys($row));
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);
		}

		$rows = $db->query('SELECT *, name, id, price FROM mysqli_test')->fetch_all(MYSQLI_NUM);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 1, 2, 3, 4, 5}", array_keys($row));
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
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);

			if ($row[3] !== null) {
				$this->assertIsString($row[3]);
			}

			$this->assertIsInt($row[4]);
			$this->assertIsNumeric($row[5]);
		}
	}

	public function testBoth(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$rows = $db->query('SELECT * FROM mysqli_test')->fetch_all(MYSQLI_BOTH);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 'id', 1, 'name', 2, 'price'}", array_keys($row));
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
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);
		}

		$rows = $db->query('SELECT * FROM ' . $this->hideValueFromPhpstan('mysqli_test'))->fetch_all(MYSQLI_BOTH);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType("array<int|string, float|int|string|null>", $row);
			}

			$this->assertSame([0, 'id', 1, 'name', 2, 'price'], array_keys($row));
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);
		}

		$stmt = $db->prepare('SELECT * FROM mysqli_test');
		$stmt->execute();
		$result = $stmt->get_result();
		assert($result instanceof mysqli_result);
		$rows = $result->fetch_all(MYSQLI_BOTH);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 'id', 1, 'name', 2, 'price'}", array_keys($row));
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
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);
		}

		$rows = $db->query('SELECT *, name, id FROM mysqli_test')->fetch_all(MYSQLI_BOTH);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 'id', 1, 'name', 2, 'price', 3, 4}", array_keys($row));
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
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
			$this->assertIsInt($row[0]);

			if ($row[1] !== null) {
				$this->assertIsString($row[1]);
			}

			$this->assertIsNumeric($row[2]);

			if ($row[3] !== null) {
				$this->assertIsString($row[3]);
			}

			$this->assertIsInt($row[4]);
		}

		$rows = $db->query('SELECT id, 5 val, "aa" id FROM mysqli_test')->fetch_all(MYSQLI_BOTH);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{0, 'id', 1, 'val', 2}", array_keys($row));
				assertType('int', $row[0]);
				assertType('string', $row['id']);
				assertType('int', $row[1]);
				assertType('int', $row['val']);
				assertType('string', $row[2]);
				assertType('*ERROR*', $row[10]);
				assertType('*ERROR*', $row['doesnt_exist']);
			}

			$this->assertSame([0, 'id', 1, 'val', 2], array_keys($row));
			$this->assertIsInt($row[0]);
			$this->assertIsString($row['id']);
			$this->assertIsInt($row[1]);
			$this->assertIsInt($row['val']);
			$this->assertIsString($row[2]);
		}
	}

	// This is not executed, it's just here as a data source for the PHPStan test.
	public function checkDynamicReturnType(int $returnType): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$rows = $db->query('SELECT * FROM mysqli_test')->fetch_all($returnType);

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

		$rows = $db->query('SELECT *, name, id FROM mysqli_test')->fetch_all($returnType);

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
		$db = TestCaseHelper::getDefaultSharedConnection();
		$rows = $db->query('SELECT col_int FROM mysqli_test_data_types')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('int', $value);
			}

			$this->assertGettype('integer', $value);
		}

		$rows = $db->query('SELECT col_varchar_null FROM mysqli_test_data_types')->fetch_all(MYSQLI_NUM);
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

		$rows = $db->query('SELECT col_float FROM mysqli_test_data_types ')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('float', $value);
			}

			$this->assertGettype('double', $value);
		}

		$rows = $db->query('SELECT col_double FROM mysqli_test_data_types')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('float', $value);
			}

			$this->assertGettype('double', $value);
		}

		$rows = $db->query('SELECT col_datetime FROM mysqli_test_data_types')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('string', $value);
			}

			$this->assertGettype('string', $value);
		}

		$rows = $db->query('SELECT null')->fetch_all(MYSQLI_NUM);
		$col = array_column($rows, 0);

		foreach ($col as $value) {
			if (function_exists('assertType')) {
				assertType('null', $value);
			}

			$this->assertGettype('NULL', $value);
		}
	}

	public function testFetchRow(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$result = $db->query('SELECT id FROM mysqli_test');

		do {
			$row = $result->fetch_row();

			if (function_exists('assertType')) {
				assertType('array{int}|false|null', $row);
			}

			if ($row === null) {
				break;
			}

			$this->assertSame([0], array_keys($row));
			$this->assertIsInt($row[0]);
		} while (true);
	}

	public function testFetchArray(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$result = $db->query('SELECT id FROM mysqli_test');

		do {
			$row = $result->fetch_array(MYSQLI_ASSOC);

			if (function_exists('assertType')) {
				assertType('array{id: int}|false|null', $row);
			}

			if ($row === null) {
				break;
			}

			$this->assertSame(['id'], array_keys($row));
			$this->assertIsInt($row['id']);
		} while (true);

		$result = $db->query('SELECT id, 5 val, "aa" id FROM mysqli_test');

		do {
			$row = $result->fetch_array(MYSQLI_ASSOC);

			if (function_exists('assertType')) {
				assertType('array{id: string, val: int}|false|null', $row);
			}

			if ($row === null) {
				break;
			}

			$this->assertSame(['id', 'val'], array_keys($row));
			$this->assertIsString($row['id']);
			$this->assertIsInt($row['val']);
		} while (true);
	}

	public function testFetchColumn(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$result = $db->query('SELECT * FROM mysqli_test');

		do {
			$value = $result->fetch_column();

			if (function_exists('assertType')) {
				assertType('int|false', $value);
			}

			if ($value === false) {
				break;
			}

			$this->assertIsInt($value);
		} while (true);

		$result = $db->query('SELECT * FROM mysqli_test');

		do {
			$value = $result->fetch_column(0);

			if (function_exists('assertType')) {
				assertType('int|false', $value);
			}

			if ($value === false) {
				break;
			}

			$this->assertIsInt($value);
		} while (true);

		$result = $db->query('SELECT * FROM mysqli_test');

		do {
			$value = $result->fetch_column(2);

			if (function_exists('assertType')) {
				assertType('numeric-string|false', $value);
			}

			if ($value === false) {
				break;
			}

			$this->assertIsNumeric($value);
		} while (true);

		$result = $db->query('SELECT id, price FROM mysqli_test');

		do {
			$dynamicColumn = $this->hideValueFromPhpstan(0);
			$value = $result->fetch_column($dynamicColumn);

			if (function_exists('assertType')) {
				// numeric-string is covered by string
				assertType('int|numeric-string|false', $value);
				assertType('int', $dynamicColumn);
			}

			if ($value === false) {
				break;
			}

			$this->assertIsNumeric($value);
		} while (true);

		$result = $db->query('SELECT * FROM mysqli_test');
		unset($value);

		try {
			$value = $result->fetch_column(10);
		} catch (\ValueError) {
		}

		if (function_exists('assertType')) {
			assertType('*ERROR*', $value);
		}
	}

	public function testDynamicSql(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();

		$rows = $db->query(
			'SELECT * FROM mysqli_test'
				. ($this->hideValueFromPhpstan(true) ? ' WHERE 1' : ' WHERE 2'),
		)->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType("array{'id', 'name', 'price'}", array_keys($row));
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('numeric-string', $row['price']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'name', 'price'], array_keys($row));
			$this->assertIsInt($row['id']);

			if ($row['name'] !== null) {
				$this->assertIsString($row['name']);
			}

			$this->assertIsNumeric($row['price']);
		}

		$rows = $db->query(
			$this->hideValueFromPhpstan(true) ? 'SELECT 1 id' : 'SELECT "aa" aa, 2 count',
		)->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType("array{aa: string, count: int}|array{id: int}", $row);
			}
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
