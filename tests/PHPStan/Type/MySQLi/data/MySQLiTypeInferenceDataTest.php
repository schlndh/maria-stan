<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi\data;

use MariaStan\DatabaseTestCase;
use mysqli;
use Nette\Schema\Expect;
use Nette\Schema\Processor;

use function array_keys;
use function function_exists;
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
		$schemaProcessor = new Processor();
		$schema = Expect::structure([
			'id' => Expect::int(),
			'name' => Expect::anyOf(Expect::string(), Expect::null()),
		]);

		foreach ($rows as $row) {
			if (function_exists('assertType')) {
				assertType('int', $row['id']);
				assertType('string|null', $row['name']);
				assertType('*ERROR*', $row['doesnt_exist']);
			}

			$this->assertEqualsCanonicalizing(['id', 'name'], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}
	}
}
