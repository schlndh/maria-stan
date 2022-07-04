<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\DatabaseTestCase;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;

class AnalyserTest extends DatabaseTestCase
{
	public function testName(): void
	{
		$tableName = 'analyser_test';
		$mysqli = $this->getDefaultSharedConnection();
		$mysqli->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NULL,
				name VARCHAR(255) NOT NULL
			);
		");

		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($mysqli);
		$analyser = new Analyser($parser, $reflection);
		$result = $analyser->analyzeQuery("SELECT * FROM {$tableName}");
		$this->assertCount(0, $result->errors);
		$this->assertEquals(
			[
				'id' => new QueryResultField(new IntType(), true),
				'name' => new QueryResultField(new VarcharType(), false),
			],
			$result->resultFields,
		);
	}
}
