<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi\data;

use MariaStan\TestCaseHelper;
use mysqli;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function function_exists;
use function PHPStan\Testing\assertType;
use function rand;

use const MYSQLI_ASSOC;
use const PHP_VERSION_ID;

class MySQLiExecuteQueryTypeInferenceDataTest extends TestCase
{
	public function testExecuteQuery(): void
	{
		if (PHP_VERSION_ID < 80200) {
			$this->markTestSkipped('This test needs PHP 8.2');
		}

		$db = TestCaseHelper::getDefaultSharedConnection();

		$rows = $db->execute_query('SELECT 1 id', [])->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType('int', $row['id']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id'], array_keys($row));
			$this->assertIsInt($row['id']);
		}

		$rows = $db->execute_query('SELECT 1 id HAVING id = ?', [1])->fetch_all(MYSQLI_ASSOC);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType('int', $row['id']);
				assertType('*ERROR*', $row[0]);
			}

			$this->assertSame(['id'], array_keys($row));
			$this->assertIsInt($row['id']);
		}
	}

	// This is not executed.
	public function checkExecuteQueryPlaceholderTypeProvider(mysqli $db): void
	{
		$row = $db->execute_query('SELECT ? val', [rand() ? 1 : 2])->fetch_assoc();

		if (function_exists('assertType')) {
			assertType("'1'|'2'", $row['val']);
		}

		$row = $db->execute_query('SELECT ? val', rand() ? [1] : ['a'])->fetch_assoc();

		if (function_exists('assertType')) {
			assertType("'1'|'a'", $row['val']);
		}

		$row = $db->execute_query('SELECT ? val', rand() ? [1] : [null])->fetch_assoc();

		if (function_exists('assertType')) {
			assertType("'1'|null", $row['val']);
		}

		$row = $db->execute_query('SELECT ? val', [null])->fetch_assoc();

		if (function_exists('assertType')) {
			assertType("null", $row['val']);
		}

		$this->expectNotToPerformAssertions();
	}
}
