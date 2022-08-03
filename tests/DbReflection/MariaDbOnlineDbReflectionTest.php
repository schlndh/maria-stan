<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DatabaseTestCaseHelper;
use MariaStan\Schema\Column;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use PHPUnit\Framework\TestCase;

class MariaDbOnlineDbReflectionTest extends TestCase
{
	public function test(): void
	{
		$tableName = 'db_reflection_test';
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NULL,
				name VARCHAR(255) NOT NULL
			);
		");
		$reflection = new MariaDbOnlineDbReflection($db);
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
