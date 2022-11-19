<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Ast\Expr\FunctionCall\StandardFunctionCall;
use MariaStan\Ast\Expr\LiteralInt;
use MariaStan\Ast\Expr\LiteralNull;
use MariaStan\Ast\Expr\LiteralString;
use MariaStan\DbReflection\Exception\TableDoesNotExistException;
use MariaStan\Schema\Column;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\EnumType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use MariaStan\TestCaseHelper;
use MariaStan\Util\MysqliUtil;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function assert;
use function fclose;
use function fwrite;
use function is_resource;
use function stream_get_meta_data;
use function strtoupper;
use function tmpfile;

class DbReflectionTest extends TestCase
{
	/** @var resource|null */
	private static $dumpFile = null;

	public static function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();

		if (! is_resource(self::$dumpFile)) {
			return;
		}

		fclose(self::$dumpFile);
		self::$dumpFile = null;
	}

	private static function initDb(): void
	{
		if (self::$dumpFile !== null) {
			return;
		}

		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE db_reflection_test (
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
		$db->query("
			CREATE OR REPLACE TABLE db_reflection_test_default_values (
				empty_string_default VARCHAR(255) DEFAULT '',
				string_default VARCHAR(255) DEFAULT 'abc',
				qmark_default VARCHAR(255) DEFAULT '?',
				col_default VARCHAR(255) DEFAULT non_default_string,
				string_default_col_name VARCHAR(255) DEFAULT 'non_default_string',
				string_default_int VARCHAR(255) DEFAULT '1',
				string_default_null VARCHAR(255) DEFAULT 'NULL',
				null_default VARCHAR(255) DEFAULT NULL,
				string_default_fn_call VARCHAR(255) DEFAULT 'round(rand())',
				int_default INT DEFAULT 1,
				int_default_string INT DEFAULT '0',
				fn_call_default INT DEFAULT ROUND(RAND()),
				non_default_string VARCHAR(255) NOT NULL
			);
		");

		self::$dumpFile = tmpfile() ?: throw new RuntimeException('tmpfile() failed!');
		fwrite(self::$dumpFile, MariaDbFileDbReflection::dumpSchema($db, MysqliUtil::getDatabaseName($db)));
	}

	/** @return iterable<string, array<mixed>> */
	public function provideDbReflections(): iterable
	{
		self::initDb();
		$db = TestCaseHelper::getDefaultSharedConnection();
		$parser = TestCaseHelper::createParser();

		yield 'online' => [new MariaDbOnlineDbReflection($db, new InformationSchemaParser($parser))];

		assert(self::$dumpFile !== null);
		$meta_data = stream_get_meta_data(self::$dumpFile);
		$filename = $meta_data["uri"];

		yield 'file' => [new MariaDbFileDbReflection($filename, new InformationSchemaParser($parser))];
	}

	/** @dataProvider provideDbReflections */
	public function test(DbReflection $reflection): void
	{
		$tableName = 'db_reflection_test';
		$parser = TestCaseHelper::createParser();
		$schema = $reflection->findTableSchema($tableName);
		// mariadb doesn't preserve the exact syntax of the default expression.
		$valDefaultExpr = $parser->parseSingleExpression('(abs(`val_mediumint`) + 5)');
		$nullExpr = $parser->parseSingleExpression('NULL');

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
			'val_timestamp' => new Column('val_timestamp', new DateTimeType(), true, $nullExpr),
			'val_year' => new Column('val_year', new DateTimeType(), false),
			'val_enum' => new Column('val_enum', new EnumType(['a', 'b', 'c']), false),
			'val_default' => new Column('val_default', new IntType(), false, $valDefaultExpr),
		], $schema->columns);
	}

	/** @dataProvider provideDbReflections */
	public function testDefaultValues(DbReflection $reflection): void
	{
		$schema = $reflection->findTableSchema('db_reflection_test_default_values');
		$this->assertStringDefaultValue('', $schema->columns['empty_string_default']);
		$this->assertStringDefaultValue('abc', $schema->columns['string_default']);
		$this->assertStringDefaultValue('?', $schema->columns['qmark_default']);

		$this->assertColumnDefaultValue('non_default_string', $schema->columns['col_default']);

		$this->assertStringDefaultValue('non_default_string', $schema->columns['string_default_col_name']);
		$this->assertStringDefaultValue('1', $schema->columns['string_default_int']);
		$this->assertStringDefaultValue('NULL', $schema->columns['string_default_null']);
		$this->assertInstanceOf(LiteralNull::class, $schema->columns['null_default']->defaultValue);
		$this->assertStringDefaultValue('round(rand())', $schema->columns['string_default_fn_call']);

		$this->assertIntDefaultValue(1, $schema->columns['int_default']);
		$this->assertIntDefaultValue(0, $schema->columns['int_default_string']);

		$this->assertFnCallDefaultValue('ROUND', $schema->columns['fn_call_default']);
	}

	/** @dataProvider provideDbReflections */
	public function testMissingTable(DbReflection $reflection): void
	{
		$this->expectException(TableDoesNotExistException::class);
		$reflection->findTableSchema('missing_table_123_abc');
	}

	private function assertStringDefaultValue(string $expected, Column $column): void
	{
		$defValueExpr = $column->defaultValue;
		$this->assertInstanceOf(LiteralString::class, $defValueExpr);
		$this->assertSame($expected, $defValueExpr->value);
	}

	private function assertColumnDefaultValue(string $expected, Column $column): void
	{
		$defValueExpr = $column->defaultValue;
		$this->assertInstanceOf(\MariaStan\Ast\Expr\Column::class, $defValueExpr);
		$this->assertSame($expected, $defValueExpr->name);
	}

	private function assertIntDefaultValue(int $expected, Column $column): void
	{
		$defValueExpr = $column->defaultValue;
		$this->assertInstanceOf(LiteralInt::class, $defValueExpr);
		$this->assertSame($expected, $defValueExpr->value);
	}

	private function assertFnCallDefaultValue(string $expected, Column $column): void
	{
		$defValueExpr = $column->defaultValue;
		$this->assertInstanceOf(StandardFunctionCall::class, $defValueExpr);
		$this->assertSame(strtoupper($expected), strtoupper($defValueExpr->name));
	}
}
