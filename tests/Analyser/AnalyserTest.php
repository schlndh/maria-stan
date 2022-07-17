<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\DatabaseTestCase;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;

use function array_keys;
use function array_map;
use function count;
use function implode;

use const MYSQLI_ASSOC;
use const MYSQLI_NOT_NULL_FLAG;
use const MYSQLI_TYPE_DATE;
use const MYSQLI_TYPE_DATETIME;
use const MYSQLI_TYPE_DECIMAL;
use const MYSQLI_TYPE_DOUBLE;
use const MYSQLI_TYPE_FLOAT;
use const MYSQLI_TYPE_INT24;
use const MYSQLI_TYPE_LONG;
use const MYSQLI_TYPE_LONGLONG;
use const MYSQLI_TYPE_NEWDECIMAL;
use const MYSQLI_TYPE_SHORT;
use const MYSQLI_TYPE_STRING;
use const MYSQLI_TYPE_TIME;
use const MYSQLI_TYPE_TIMESTAMP;
use const MYSQLI_TYPE_TINY;
use const MYSQLI_TYPE_VAR_STRING;
use const MYSQLI_TYPE_YEAR;

class AnalyserTest extends DatabaseTestCase
{
	/** @return iterable<string, array<mixed>> */
	public function provideTestData(): iterable
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
		$idField = new QueryResultField('id', new IntType(), false);
		$nameField = new QueryResultField('name', new VarcharType(), true);

		yield 'SELECT *' => [
			'query' => "SELECT * FROM {$tableName}",
			'expected fields' => [
				$idField,
				$nameField,
			],
			'expected schema' => Expect::structure([
				'id' => Expect::int(),
				'name' => Expect::anyOf(Expect::string(), Expect::null()),
			]),
		];

		yield 'manually specified columns' => [
			'query' => "SELECT name, id FROM {$tableName}",
			'expected fields' => [
				$nameField,
				$idField,
			],
			'expected schema' => Expect::structure([
				'id' => Expect::int(),
				'name' => Expect::anyOf(Expect::string(), Expect::null()),
			]),
		];

		yield 'manually specified columns + *' => [
			'query' => "SELECT *, name, id FROM {$tableName}",
			'expected fields' => [
				$idField,
				$nameField,
				$nameField,
				$idField,
			],
			'expected schema' => Expect::structure([
				'id' => Expect::int(),
				'name' => Expect::anyOf(Expect::string(), Expect::null()),
			]),
		];

		yield 'field alias' => [
			'query' => "SELECT 1 id",
			'expected fields' => [
				$idField,
			],
			'expected schema' => Expect::structure([
				'id' => Expect::int(),
			]),
		];

		yield from $this->provideLiteralData();
		yield from $this->provideDataTypeData();
		yield from $this->provideJoinData();
	}

	/** @return iterable<string, array<mixed>> */
	private function provideLiteralData(): iterable
	{
		yield 'literal - int' => [
			'query' => "SELECT 5",
			'expected fields' => [new QueryResultField('5', new IntType(), false)],
			'expected schema' => Expect::structure(['5' => Expect::int()]),
		];

		yield 'literal - float - normal notation' => [
			'query' => "SELECT 5.5",
			'expected fields' => [new QueryResultField('5.5', new DecimalType(), false)],
			'expected schema' => Expect::structure(['5.5' => Expect::string()]),
		];

		yield 'literal - float - exponent notation' => [
			'query' => "SELECT 5.5e0",
			'expected fields' => [new QueryResultField('5.5e0', new FloatType(), false)],
			'expected schema' => Expect::structure(['5.5e0' => Expect::float()]),
		];

		// TODO: enable this once literal string support is added
		//yield 'literal - string' => [
		//	'query' => "SELECT 'a'",
		//	'expected fields' => [new QueryResultField('a', new VarcharType(), false)],
		//	'expected schema' => Expect::structure(['a' => Expect::string()]),
		//];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideDataTypeData(): iterable
	{
		$db = $this->getDefaultSharedConnection();
		$dataTypesTable = 'analyser_test_data_types';
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

		yield 'column - int' => [
			'query' => "SELECT col_int FROM {$dataTypesTable}",
			'expected fields' => [new QueryResultField('col_int', new IntType(), false)],
			'expected schema' => Expect::structure(['col_int' => Expect::int()]),
		];

		yield 'column - varchar nullable' => [
			'query' => "SELECT col_varchar_null FROM {$dataTypesTable}",
			'expected fields' => [new QueryResultField('col_varchar_null', new VarcharType(), true)],
			'expected schema' => Expect::structure(
				['col_varchar_null' => Expect::anyOf(Expect::string(), Expect::null())],
			),
		];

		yield 'column - decimal' => [
			'query' => "SELECT col_decimal FROM {$dataTypesTable}",
			'expected fields' => [new QueryResultField('col_decimal', new DecimalType(), false)],
			'expected schema' => Expect::structure(['col_decimal' => Expect::string()]),
		];

		yield 'column - float' => [
			'query' => "SELECT col_float FROM {$dataTypesTable}",
			'expected fields' => [new QueryResultField('col_float', new FloatType(), false)],
			'expected schema' => Expect::structure(['col_float' => Expect::float()]),
		];

		yield 'column - double' => [
			'query' => "SELECT col_double FROM {$dataTypesTable}",
			'expected fields' => [new QueryResultField('col_double', new FloatType(), false)],
			'expected schema' => Expect::structure(['col_double' => Expect::float()]),
		];

		yield 'column - datetime' => [
			'query' => "SELECT col_datetime FROM {$dataTypesTable}",
			'expected fields' => [new QueryResultField('col_datetime', new DateTimeType(), false)],
			'expected schema' => Expect::structure(['col_datetime' => Expect::string()]),
		];

		// TODO: fix missing types: ~ is unsigned 64b int, so it's too large for PHP.
		// TODO: name of 2nd column contains comment: SELECT col_int, /*aaa*/ -col_int FROM mysqli_test_data_types
		// TODO: check type return by fetch_fields as well?
		yield 'unary ops' => [
			'query' => "
				SELECT
				    -col_int, +col_int, !col_int,
				    -col_varchar_null, +col_varchar_null, !col_varchar_null,
				    -col_decimal, +col_decimal, !col_decimal,
				    -col_float, +col_float, !col_float,
				    -col_double, +col_double, !col_double,
				    -col_datetime, +col_datetime, !col_datetime
				FROM {$dataTypesTable}
			",
			'expected fields' => [
				new QueryResultField('-col_int', new IntType(), false),
				new QueryResultField('col_int', new IntType(), false),
				new QueryResultField('!col_int', new IntType(), false),

				new QueryResultField('-col_varchar_null', new FloatType(), true),
				new QueryResultField('col_varchar_null', new VarcharType(), true),
				new QueryResultField('!col_varchar_null', new IntType(), true),

				new QueryResultField('-col_decimal', new DecimalType(), false),
				new QueryResultField('col_decimal', new DecimalType(), false),
				new QueryResultField('!col_decimal', new IntType(), false),

				new QueryResultField('-col_float', new FloatType(), false),
				new QueryResultField('col_float', new FloatType(), false),
				new QueryResultField('!col_float', new IntType(), false),

				new QueryResultField('-col_double', new FloatType(), false),
				new QueryResultField('col_double', new FloatType(), false),
				new QueryResultField('!col_double', new IntType(), false),

				new QueryResultField('-col_datetime', new DecimalType(), false),
				new QueryResultField('col_datetime', new DateTimeType(), false),
				new QueryResultField('!col_datetime', new IntType(), false),
			],
			'expected schema' => Expect::structure([
				'-col_int' => Expect::int(),
				'col_int' => Expect::int(),
				'!col_int' => Expect::int(),

				'-col_varchar_null' => Expect::anyOf(Expect::float(), Expect::null()),
				'col_varchar_null' => Expect::anyOf(Expect::string(), Expect::null()),
				'!col_varchar_null' => Expect::anyOf(Expect::int(), Expect::string(), Expect::null()),

				'-col_decimal' => Expect::string(),
				'col_decimal' => Expect::string(),
				'!col_decimal' => Expect::int(),

				'-col_float' => Expect::float(),
				'col_float' => Expect::float(),
				'!col_float' => Expect::int(),

				'-col_double' => Expect::float(),
				'col_double' => Expect::float(),
				'!col_double' => Expect::int(),

				'-col_datetime' => Expect::string(),
				'col_datetime' => Expect::string(),
				'!col_datetime' => Expect::int(),
			]),
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideJoinData(): iterable
	{
		$db = $this->getDefaultSharedConnection();
		$joinTableA = 'analyser_test_join_a';
		$db->query("
			CREATE OR REPLACE TABLE {$joinTableA} (
				id INT NOT NULL,
				name VARCHAR(255) NOT NULL
			);
		");
		$db->query("
			INSERT INTO {$joinTableA} (id, name)
			VALUES (1, 'aa'), (2, 'bb')
		");

		$joinTableB = 'analyser_test_join_b';
		$db->query("
			CREATE OR REPLACE TABLE {$joinTableB} (
				id INT NOT NULL,
				created_at DATETIME NOT NULL DEFAULT NOW()
			);
		");
		$db->query("INSERT INTO {$joinTableB} (id) VALUES (1), (2), (3)");

		$crossJoinAllFields = [
			new QueryResultField('id', new IntType(), false),
			new QueryResultField('name', new VarcharType(), false),
			new QueryResultField('id', new IntType(), false),
			new QueryResultField('created_at', new DateTimeType(), false),
		];
		$crossJoinAllFieldsSchema = Expect::structure([
			'id' => Expect::int(),
			'name' => Expect::string(),
			'created_at' => Expect::string(),
		]);

		yield 'CROSS JOIN - comma, *' => [
			'query' => "SELECT * FROM {$joinTableA}, {$joinTableB}",
			'expected fields' => $crossJoinAllFields,
			'expected schema' => $crossJoinAllFieldsSchema,
		];

		yield 'CROSS JOIN - explicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} CROSS JOIN {$joinTableB}",
			'expected fields' => $crossJoinAllFields,
			'expected schema' => $crossJoinAllFieldsSchema,
		];

		yield 'CROSS JOIN - explicit, listed columns' => [
			'query' => "SELECT created_at, name FROM {$joinTableA} CROSS JOIN {$joinTableB}",
			'expected fields' => [
				new QueryResultField('created_at', new DateTimeType(), false),
				new QueryResultField('name', new VarcharType(), false),
			],
			'expected schema' => Expect::structure([
				'created_at' => Expect::string(),
				'name' => Expect::string(),
			]),
		];

		yield 'INNER JOIN - implicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} JOIN {$joinTableB} ON 1",
			'expected fields' => $crossJoinAllFields,
			'expected schema' => $crossJoinAllFieldsSchema,
		];

		yield 'LEFT OUTER JOIN - implicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} LEFT JOIN {$joinTableB} ON 1",
			'expected fields' => [
				new QueryResultField('id', new IntType(), false),
				new QueryResultField('name', new VarcharType(), false),
				new QueryResultField('id', new IntType(), true),
				new QueryResultField('created_at', new DateTimeType(), true),
			],
			'expected schema' => $crossJoinAllFieldsSchema,
		];

		yield 'RIGHT OUTER JOIN - implicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} RIGHT JOIN {$joinTableB} ON 1",
			'expected fields' => [
				new QueryResultField('id', new IntType(), true),
				new QueryResultField('name', new VarcharType(), true),
				new QueryResultField('id', new IntType(), false),
				new QueryResultField('created_at', new DateTimeType(), false),
			],
			'expected schema' => $crossJoinAllFieldsSchema,
		];

		yield 'LEFT OUTER JOIN - explicit, aliases vs column without table name' => [
			'query' => "SELECT created_at FROM {$joinTableA} a LEFT JOIN {$joinTableB} b ON 1",
			'expected fields' => [
				new QueryResultField('created_at', new DateTimeType(), true),
			],
			'expected schema' => Expect::structure([
				'created_at' => Expect::string(),
			]),
		];

		yield 'multiple JOINs - track outer JOINs - LEFT' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid
				FROM {$joinTableA} a
				LEFT JOIN {$joinTableB} b ON 1
				INNER JOIN {$joinTableB} c ON 1
			",
			'expected fields' => [
				new QueryResultField('aid', new IntType(), false),
				new QueryResultField('bid', new IntType(), true),
				new QueryResultField('cid', new IntType(), false),
			],
			'expected schema' => Expect::structure([
				'aid' => Expect::int(),
				'bid' => Expect::int(),
				'cid' => Expect::int(),
			]),
		];

		yield 'multiple JOINs - track outer JOINs - RIGHT' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid
				FROM {$joinTableA} a
				RIGHT JOIN {$joinTableB} b ON 1
				INNER JOIN {$joinTableB} c ON 1
			",
			'expected fields' => [
				new QueryResultField('aid', new IntType(), true),
				new QueryResultField('bid', new IntType(), false),
				new QueryResultField('cid', new IntType(), false),
			],
			'expected schema' => Expect::structure([
				'aid' => Expect::int(),
				'bid' => Expect::int(),
				'cid' => Expect::int(),
			]),
		];

		yield 'multiple JOINs - track outer JOINs - RIGHT - multiple tables before' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid
				FROM {$joinTableA} a
				INNER JOIN {$joinTableB} b ON 1
				RIGHT JOIN {$joinTableB} c ON 1
			",
			'expected fields' => [
				new QueryResultField('aid', new IntType(), true),
				new QueryResultField('bid', new IntType(), true),
				new QueryResultField('cid', new IntType(), false),
			],
			'expected schema' => Expect::structure([
				'aid' => Expect::int(),
				'bid' => Expect::int(),
				'cid' => Expect::int(),
			]),
		];

		yield 'multiple JOINs - track outer JOINs - LEFT - multiple after' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid, d.id did
				FROM {$joinTableA} a
				LEFT JOIN {$joinTableB} b ON 1
				JOIN {$joinTableB} c ON 1
				JOIN {$joinTableB} d ON 1
			",
			'expected fields' => [
				new QueryResultField('aid', new IntType(), false),
				new QueryResultField('bid', new IntType(), true),
				new QueryResultField('cid', new IntType(), false),
				new QueryResultField('did', new IntType(), false),
			],
			'expected schema' => Expect::structure([
				'aid' => Expect::int(),
				'bid' => Expect::int(),
				'cid' => Expect::int(),
				'did' => Expect::int(),
			]),
		];
	}

	/**
	 * @param array<QueryResultField> $expectedFields
	 * @dataProvider provideTestData
	 */
	public function test(string $query, array $expectedFields, Schema $expectedSchema): void
	{
		$db = $this->getDefaultSharedConnection();

		$schemaProcessor = new Processor();
		$stmt = $db->query($query);
		$expectedFieldKeys = $this->getExpectedFieldKeys($expectedFields);
		$fields = $stmt->fetch_fields();
		$this->assertSameSize($expectedFields, $fields);

		for ($i = 0; $i < count($fields); $i++) {
			$field = $fields[$i];
			$expectedField = $expectedFields[$i];
			$this->assertSame($expectedField->name, $field->name);
			$isFieldNullable = ! ($field->flags & MYSQLI_NOT_NULL_FLAG);
			$this->assertSame($expectedField->isNullable, $isFieldNullable);
			$actualType = $this->mysqliTypeToDbTypeEnum($field->type);
			$this->assertSame($expectedField->type::getTypeEnum(), $actualType);
		}

		foreach ($stmt->fetch_all(MYSQLI_ASSOC) as $row) {
			$this->assertSame($expectedFieldKeys, array_keys($row));
			$schemaProcessor->process($expectedSchema, $row);
		}

		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db);
		$analyser = new Analyser($parser, $reflection);
		$result = $analyser->analyzeQuery($query);
		$this->assertCount(
			0,
			$result->errors,
			"Expected no errors. Got: "
				. implode("\n", array_map(static fn (AnalyserError $e) => $e->message, $result->errors)),
		);
		$this->assertEquals($expectedFields, $result->resultFields);
	}

	/**
	 * @param array<QueryResultField> $expectedFields
	 * @return array<string> without duplicates, in the same order as returned by the query
	 */
	private function getExpectedFieldKeys(array $expectedFields): array
	{
		$result = [];

		foreach ($expectedFields as $field) {
			$result[$field->name] = 1;
		}

		return array_keys($result);
	}

	private function mysqliTypeToDbTypeEnum(int $type): DbTypeEnum
	{
		return match ($type) {
			MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL => DbTypeEnum::DECIMAL,
			MYSQLI_TYPE_TINY /* =  MYSQLI_TYPE_CHAR */, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_INT24, MYSQLI_TYPE_LONG,
				MYSQLI_TYPE_LONGLONG => DbTypeEnum::INT,
			MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE => DbTypeEnum::FLOAT,
			MYSQLI_TYPE_TIMESTAMP, MYSQLI_TYPE_DATE, MYSQLI_TYPE_TIME, MYSQLI_TYPE_DATETIME, MYSQLI_TYPE_YEAR
				=> DbTypeEnum::DATETIME,
			MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_STRING => DbTypeEnum::VARCHAR,
			// TODO: MYSQLI_TYPE_NULL, MYSQLI_TYPE_ENUM, MYSQLI_TYPE_BIT, MYSQLI_TYPE_INTERVAL, MYSQLI_TYPE_SET,
			// MYSQLI_TYPE_GEOMETRY, MYSQLI_TYPE_JSON, blob/binary types
			default => throw new \RuntimeException("Unhandled type {$type}"),
		};
	}
}
