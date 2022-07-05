<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\DatabaseTestCase;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use Nette\Schema\Expect;
use Nette\Schema\Processor;

class AnalyserTest extends DatabaseTestCase
{
	public function testName(): void
	{
		$tableName = 'analyser_test';
		$db = $this->getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NOT NULL,
				name VARCHAR(255) NULL
			);
		");
		$db->query("INSERT INTO {$tableName} (id, name) VALUES (1, 'aa'), (2, NULL)");

		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db);
		$analyser = new Analyser($parser, $reflection);
		$query = "SELECT * FROM {$tableName}";
		$result = $analyser->analyzeQuery($query);
		$this->assertCount(0, $result->errors);
		$this->assertEquals(
			[
				'id' => new QueryResultField(new IntType(), false),
				'name' => new QueryResultField(new VarcharType(), true),
			],
			$result->resultFields,
		);
		$schemaProcessor = new Processor();
		$schema = Expect::structure([
			'id' => Expect::int(),
			'name' => Expect::anyOf(Expect::string(), Expect::null()),
		]);

		foreach ($db->query($query)->fetch_all(MYSQLI_ASSOC) as $row) {
			$this->assertEqualsCanonicalizing(['id', 'name'], array_keys($row));
			$schemaProcessor->process($schema, $row);
		}
	}
}
