<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi\data;

use MariaStan\DatabaseTestCase;
use mysqli;
use Nette\Schema\Expect;
use Nette\Schema\Processor;

use function array_key_exists;
use function array_keys;
use function function_exists;
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
				name VARCHAR(255) NULL
			);
		');
		$db->query('INSERT INTO mysqli_test (id, name) VALUES (1, "aa"), (2, NULL)');
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
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists('id', $row));
				assertType('true', array_key_exists('name', $row));
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('*ERROR*', $row['doesnt_exist']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id', 'name'], array_keys($row));
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
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists(0, $row));
				assertType('true', array_key_exists(1, $row));
				assertType('int', $row[0]);
				assertType('string|null', $row[1]);
				assertType('*ERROR*', $row[2]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1], array_keys($row));
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
				assertType('*ERROR*', $row[2]);
				assertType('*ERROR*', $row['id']);
			}

			$this->assertSame([0, 1], array_keys($row));
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
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				// All items are non-optional
				assertType('true', array_key_exists('id', $row));
				assertType('true', array_key_exists('name', $row));
				assertType('true', array_key_exists(0, $row));
				assertType('true', array_key_exists(1, $row));
				assertType('int', $row[0]);
				assertType('int', $row['id']);
				assertType('string|null', $row[1]);
				assertType('string|null', $row['name']);
				assertType('*ERROR*', $row[2]);
				assertType('*ERROR*', $row['doesnt_exist']);
			}

			$this->assertSame([0, 'id', 1, 'name'], array_keys($row));
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
			assertType('bool', array_key_exists(0, $row));
			assertType('bool', array_key_exists(1, $row));
			assertType('int', $row[0]);
			assertType('int', $row['id']);
			assertType('string|null', $row[1]);
			assertType('string|null', $row['name']);
			assertType('*ERROR*', $row[2]);
			assertType('*ERROR*', $row['doesnt_exist']);
		}
	}
}
