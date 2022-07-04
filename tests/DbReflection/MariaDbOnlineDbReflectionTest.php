<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DatabaseTestCase;
use MariaStan\Schema\Column;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;

class MariaDbOnlineDbReflectionTest extends DatabaseTestCase
{
	public function test(): void
	{
		$tableName = 'db_reflection_test';
		$mysqli = $this->getDefaultSharedConnection();
		$mysqli->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NULL,
				name VARCHAR(255) NOT NULL
			);
		");
		$reflection = new MariaDbOnlineDbReflection($mysqli);
		$schema = $reflection->findTableSchema($tableName);

		$this->assertNotNull($schema);
		$this->assertSame($tableName, $schema->name);
		$this->assertCount(2, $schema->columns);
		$this->assertEquals([
			'id' => new Column('id', new IntType(), true),
			'name' => new Column('name', new VarcharType(), false),
		], $schema->columns);
	}
}
