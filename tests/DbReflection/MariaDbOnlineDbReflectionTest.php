<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DatabaseTestCaseHelper;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\Column;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\EnumType;
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
				id INT NULL PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(255) NOT NULL,
				val_tinyint_u TINYINT(1) UNSIGNED NOT NULL,
				val_tinyint_s TINYINT(1) SIGNED NOT NULL,
				val_tinyint_z TINYINT(1) ZEROFILL NOT NULL,
				val_smallint SMALLINT NOT NULL,
				val_mediumint MEDIUMINT NOT NULL,
				val_bigint BIGINT NOT NULL,
				val_tinytext TINYTEXT NOT NULL,
				val_text TEXT NOT NULL,
				val_mediumtext MEDIUMTEXT NOT NULL,
				val_longtext LONGTEXT NOT NULL,
				val_char CHAR(5) NOT NULL,
				val_date DATE NOT NULL,
				val_time TIME NOT NULL,
				val_datetime DATETIME NOT NULL,
				val_timestamp TIMESTAMP NULL,
				val_year YEAR NOT NULL,
				val_enum ENUM('a', 'b', 'c') NOT NULL,
				val_default INT NOT NULL DEFAULT (ABS(val_mediumint) + 5)
			);
		");

		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db, $parser);
		$schema = $reflection->findTableSchema($tableName);
		// mariadb doesn't preserve the exact syntax of the default expression.
		$valDefaultExpr = $parser->parseSingleExpression('(abs(`val_mediumint`) + 5)');

		$this->assertNotNull($schema);
		$this->assertSame($tableName, $schema->name);
		$this->assertEquals([
			'id' => new Column('id', new IntType(), false, null, true),
			'name' => new Column('name', new VarcharType(), false),
			'val_tinyint_u' => new Column('val_tinyint_u', new IntType(), false),
			'val_tinyint_s' => new Column('val_tinyint_s', new IntType(), false),
			'val_tinyint_z' => new Column('val_tinyint_z', new IntType(), false),
			'val_smallint' => new Column('val_smallint', new IntType(), false),
			'val_mediumint' => new Column('val_mediumint', new IntType(), false),
			'val_bigint' => new Column('val_bigint', new IntType(), false),
			'val_tinytext' => new Column('val_tinytext', new VarcharType(), false),
			'val_text' => new Column('val_text', new VarcharType(), false),
			'val_mediumtext' => new Column('val_mediumtext', new VarcharType(), false),
			'val_longtext' => new Column('val_longtext', new VarcharType(), false),
			'val_char' => new Column('val_char', new VarcharType(), false),
			'val_date' => new Column('val_date', new DateTimeType(), false),
			'val_time' => new Column('val_time', new DateTimeType(), false),
			'val_datetime' => new Column('val_datetime', new DateTimeType(), false),
			'val_timestamp' => new Column('val_timestamp', new DateTimeType(), true),
			'val_year' => new Column('val_year', new DateTimeType(), false),
			'val_enum' => new Column('val_enum', new EnumType(['a', 'b', 'c']), false),
			'val_default' => new Column('val_default', new IntType(), false, $valDefaultExpr),
		], $schema->columns);
	}
}
