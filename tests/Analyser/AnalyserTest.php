<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\DatabaseTestCaseHelper;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Util\MariaDbErrorCodes;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function count;
use function implode;

use const MYSQLI_ASSOC;
use const MYSQLI_ENUM_FLAG;
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
use const MYSQLI_TYPE_NULL;
use const MYSQLI_TYPE_SHORT;
use const MYSQLI_TYPE_STRING;
use const MYSQLI_TYPE_TIME;
use const MYSQLI_TYPE_TIMESTAMP;
use const MYSQLI_TYPE_TINY;
use const MYSQLI_TYPE_VAR_STRING;
use const MYSQLI_TYPE_YEAR;

class AnalyserTest extends TestCase
{
	/** @return iterable<string, array<mixed>> */
	public function provideTestData(): iterable
	{
		$tableName = 'analyser_test';
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NOT NULL,
				name VARCHAR(255) NULL
			);
		");
		$db->query("INSERT INTO {$tableName} (id, name) VALUES (1, 'aa'), (2, NULL)");

		yield 'SELECT *' => [
			'query' => "SELECT * FROM {$tableName}",
		];

		yield 'manually specified columns' => [
			'query' => "SELECT name, id FROM {$tableName}",
		];

		yield 'manually specified columns + *' => [
			'query' => "SELECT *, name, id FROM {$tableName}",
		];

		yield 'field alias' => [
			'query' => "SELECT 1 id",
		];

		yield from $this->provideLiteralData();
		yield from $this->provideOperatorTestData();
		yield from $this->provideDataTypeData();
		yield from $this->provideJoinData();
		yield from $this->provideSubqueryTestData();
	}

	/** @return iterable<string, array<mixed>> */
	private function provideLiteralData(): iterable
	{
		yield 'literal - int' => [
			'query' => "SELECT 5",
		];

		yield 'literal - float - normal notation' => [
			'query' => "SELECT 5.5",
		];

		yield 'literal - float - exponent notation' => [
			'query' => "SELECT 5.5e0",
		];

		yield 'literal - null' => [
			'query' => "SELECT null",
		];

		yield 'literal - string' => [
			'query' => "SELECT 'a'",
		];

		yield 'literal - string concat' => [
			'query' => "SELECT 'a' 'bb'",
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideDataTypeData(): iterable
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$dataTypesTable = 'analyser_test_data_types';
		$db->query("
			CREATE OR REPLACE TABLE {$dataTypesTable} (
				col_int INT NOT NULL,
				col_varchar_null VARCHAR(255) NULL,
				col_decimal DECIMAL(10, 2) NOT NULL,
				col_float FLOAT NOT NULL,
				col_double DOUBLE NOT NULL,
				col_datetime DATETIME NOT NULL,
				col_enum ENUM('a', 'b', 'c') NOT NULL
			);
		");
		$db->query("
			INSERT INTO {$dataTypesTable} (col_int, col_varchar_null, col_decimal, col_float, col_double, col_datetime)
			VALUES (1, 'aa', 111.11, 11.11, 1.1, NOW()), (2, NULL, 222.22, 22.22, 2.2, NOW())
		");

		yield 'column - int' => [
			'query' => "SELECT col_int FROM {$dataTypesTable}",
		];

		yield 'column - varchar nullable' => [
			'query' => "SELECT col_varchar_null FROM {$dataTypesTable}",
		];

		yield 'column - decimal' => [
			'query' => "SELECT col_decimal FROM {$dataTypesTable}",
		];

		yield 'column - float' => [
			'query' => "SELECT col_float FROM {$dataTypesTable}",
		];

		yield 'column - double' => [
			'query' => "SELECT col_double FROM {$dataTypesTable}",
		];

		yield 'column - datetime' => [
			'query' => "SELECT col_datetime FROM {$dataTypesTable}",
		];

		yield 'column - enum' => [
			'query' => "SELECT col_enum FROM {$dataTypesTable}",
		];

		// TODO: fix missing types: ~ is unsigned 64b int, so it's too large for PHP.
		// TODO: name of 2nd column contains comment: SELECT col_int, /*aaa*/ -col_int FROM mysqli_test_data_types
		yield 'unary ops' => [
			'query' => "
				SELECT
				    -col_int, +col_int, !col_int, ~col_int,
				    -col_varchar_null, +col_varchar_null, !col_varchar_null, ~col_varchar_null,
				    -col_decimal, +col_decimal, !col_decimal, ~col_decimal,
				    -col_float, +col_float, !col_float, ~col_float,
				    -col_double, +col_double, !col_double, ~col_double,
				    -col_datetime, +col_datetime, !col_datetime, ~col_datetime
				FROM {$dataTypesTable}
			",
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideJoinData(): iterable
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
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

		yield 'CROSS JOIN - comma, *' => [
			'query' => "SELECT * FROM {$joinTableA}, {$joinTableB}",
		];

		yield 'CROSS JOIN - explicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} CROSS JOIN {$joinTableB}",
		];

		yield 'CROSS JOIN - explicit, listed columns' => [
			'query' => "SELECT created_at, name FROM {$joinTableA} CROSS JOIN {$joinTableB}",
		];

		yield 'INNER JOIN - implicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} JOIN {$joinTableB} ON 1",
		];

		yield 'LEFT OUTER JOIN - implicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} LEFT JOIN {$joinTableB} ON 1",
		];

		yield 'RIGHT OUTER JOIN - implicit, *' => [
			'query' => "SELECT * FROM {$joinTableA} RIGHT JOIN {$joinTableB} ON 1",
		];

		yield 'LEFT OUTER JOIN - explicit, aliases vs column without table name' => [
			'query' => "SELECT created_at FROM {$joinTableA} a LEFT JOIN {$joinTableB} b ON 1",
		];

		yield 'LEFT OUTER JOIN - parentheses' => [
			'query' => "
				SELECT * FROM (analyser_test a, analyser_test b)
				LEFT OUTER JOIN (analyser_test c, analyser_test d) ON 0
			",
		];

		yield 'RIGHT OUTER JOIN - parentheses' => [
			'query' => "
				SELECT * FROM (analyser_test a, analyser_test b)
				RIGHT OUTER JOIN (analyser_test c, analyser_test d) ON 0
			",
		];

		yield 'multiple JOINs - track outer JOINs - LEFT' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid
				FROM {$joinTableA} a
				LEFT JOIN {$joinTableB} b ON 1
				INNER JOIN {$joinTableB} c ON 1
			",
		];

		yield 'multiple JOINs - track outer JOINs - RIGHT' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid
				FROM {$joinTableA} a
				RIGHT JOIN {$joinTableB} b ON 1
				INNER JOIN {$joinTableB} c ON 1
			",
		];

		yield 'multiple JOINs - track outer JOINs - RIGHT - multiple tables before' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid
				FROM {$joinTableA} a
				INNER JOIN {$joinTableB} b ON 1
				RIGHT JOIN {$joinTableB} c ON 1
			",
		];

		yield 'multiple JOINs - track outer JOINs - LEFT - multiple after' => [
			'query' => "
				SELECT a.id aid, b.id bid, c.id cid, d.id did
				FROM {$joinTableA} a
				LEFT JOIN {$joinTableB} b ON 1
				JOIN {$joinTableB} c ON 1
				JOIN {$joinTableB} d ON 1
			",
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideOperatorTestData(): iterable
	{
		$operators = ['+', '-', '*', '/', '%', 'DIV'];

		foreach ($operators as $op) {
			foreach (['1', '1.0', '"a"'] as $other) {
				$expr = "NULL {$op} {$other}";

				yield "operator {$expr}" => [
					'query' => "SELECT {$expr}",
				];
			}
		}

		foreach (['+', '-', '*', '/', '%', 'DIV'] as $op) {
			$expr = "1 {$op} 2";

			yield "operator {$expr}" => [
				'query' => "SELECT {$expr}",
			];

			$expr = "1 {$op} 2.0";

			yield "operator {$expr}" => [
				'query' => "SELECT {$expr}",
			];

			foreach (['1', '1.0'] as $other) {
				$expr = "'a' {$op} {$other}";

				yield "operator {$expr}" => [
					'query' => "SELECT {$expr}",
				];
			}

			$expr = "'a' {$op} 'b'";

			yield "operator {$expr}" => [
				'query' => "SELECT {$expr}",
			];
		}

		$exprs = [
			'1 IS TRUE',
			'1 IS NOT TRUE',
			'NULL IS TRUE',
			'1 BETWEEN 0 AND 2',
			'1 NOT BETWEEN 0 AND 2',
			'1 NOT BETWEEN 0 AND NULL',
			'1 NOT BETWEEN NULL AND 2',
			'NULL BETWEEN 0 AND 2',
		];

		foreach ($exprs as $expr) {
			yield "operator {$expr}" => [
				'query' => "SELECT {$expr}",
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private function provideSubqueryTestData(): iterable
	{
		yield 'subquery as SELECT expression' => [
			'query' => 'SELECT (SELECT 1)',
		];

		yield 'subquery as SELECT expression - reference to outer table' => [
			'query' => 'SELECT (SELECT id FROM analyser_test WHERE id = t_out.id LIMIT 1) FROM analyser_test t_out',
		];

		yield 'subquery as SELECT expression - same name as outer table' => [
			'query' => 'SELECT (SELECT id FROM analyser_test WHERE id = analyser_test.id LIMIT 1) FROM analyser_test',
		];

		yield 'subquery as SELECT expression - reference field from outer table' => [
			'query' => 'SELECT (SELECT name) FROM analyser_test',
		];

		yield 'subquery as SELECT expression - same field name as outer table' => [
			'query' => 'SELECT (SELECT name FROM analyser_test LIMIT 1) FROM analyser_test',
		];

		yield 'subquery in FROM' => [
			'query' => 'SELECT t.`1` FROM (SELECT 1) t',
		];

		yield 'SELECT * FROM subquery' => [
			'query' => 'SELECT * FROM (SELECT * FROM analyser_test) t',
		];

		yield 'subquery in FROM - reuse outer alias inside subquery' => [
			'query' => 'SELECT * FROM (SELECT 1) t, (SELECT 1 FROM (SELECT 1) t) b',
		];
	}

	/** @dataProvider provideTestData */
	public function test(string $query): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
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

		$stmt = $db->query($query);
		$fieldKeys = $this->getFieldKeys($result->resultFields);
		$fields = $stmt->fetch_fields();
		$this->assertSameSize($result->resultFields, $fields);
		$forceNullsForColumns = [];
		$unnecessaryNullableFields = [];

		for ($i = 0; $i < count($fields); $i++) {
			$field = $fields[$i];
			$parserField = $result->resultFields[$i];
			$this->assertSame($parserField->name, $field->name);
			$isFieldNullable = ! ($field->flags & MYSQLI_NOT_NULL_FLAG);

			if ($parserField->isNullable && ! $isFieldNullable) {
				$unnecessaryNullableFields[] = $parserField->name;
			} else {
				$this->assertSame($isFieldNullable, $parserField->isNullable);
			}

			$actualType = $this->mysqliTypeToDbTypeEnum($field->type);

			// It seems that in some cases the type returned by the database does not propagate NULL in all cases.
			// E.g. 1 + NULL is double for some reason. Let's allow the analyser to get away with null, but force
			// check that the returned values are all null.
			if ($parserField->type::getTypeEnum() === DbTypeEnum::NULL && $field->type !== MYSQLI_TYPE_NULL) {
				$forceNullsForColumns[$field->name] = true;
			} elseif ($parserField->type::getTypeEnum() === DbTypeEnum::ENUM) {
				$this->assertTrue(($field->flags & MYSQLI_ENUM_FLAG) !== 0);
				$this->assertSame(DbTypeEnum::VARCHAR, $actualType);
			} else {
				$this->assertSame(
					$parserField->type::getTypeEnum(),
					$actualType,
					"The test says {$parserField->name} should be {$parserField->type::getTypeEnum()->name} "
					. "but got {$actualType->name} from the database.",
				);
			}
		}

		foreach ($stmt->fetch_all(MYSQLI_ASSOC) as $row) {
			$this->assertSame($fieldKeys, array_keys($row));

			foreach (array_keys($forceNullsForColumns) as $col) {
				$this->assertNull($row[$col]);
			}
		}

		if (count($unnecessaryNullableFields) > 0) {
			$this->markTestIncomplete(
				"These fields don't have to be nullable:\n" . implode(",\n", $unnecessaryNullableFields),
			);
		}
	}

	/** @return iterable<string, array<mixed>> */
	public function provideInvalidData(): iterable
	{
		// TODO: improve the error messages to match MariaDB errors more closely.
		yield 'unknown column in field list' => [
			'query' => 'SELECT v.id FROM analyser_test',
			'error' => 'Unknown column v.id',
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in field list' => [
			'query' => 'SELECT (SELECT v.id FROM analyser_test)',
			'error' => 'Unknown column v.id',
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in FROM' => [
			'query' => 'SELECT * FROM (SELECT v.id FROM analyser_test) t JOIN analyser_test v',
			'error' => 'Unknown column v.id',
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - IS' => [
			'query' => 'SELECT v.id IS NULL FROM analyser_test',
			'error' => 'Unknown column v.id',
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'not unique table name in top-level query' => [
			'query' => 'SELECT * FROM analyser_test, analyser_test',
			'error' => "Not unique table/alias: 'analyser_test'",
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique table alias in top-level query' => [
			'query' => 'SELECT * FROM analyser_test t, analyser_test t',
			'error' => "Not unique table/alias: 't'",
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique subquery alias' => [
			'query' => 'SELECT * FROM (SELECT 1) t, (SELECT 1) t',
			'error' => "Not unique table/alias: 't'",
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		// TODO: implement this
		yield 'not unique table in subquery' => [
			'query' => 'SELECT * FROM (SELECT * FROM analyser_test, analyser_test) t',
			'error' => "Not unique table/alias: 'analyser_test'",
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		//yield 'duplicate column name in subquery' => [
		//	'query' => 'SELECT * FROM (SELECT * FROM analyser_test a, analyser_test b) t',
		//	'error' => "Duplicate column name 'id'",
		//	'DB error code' => MariaDbErrorCodes::ER_DUP_FIELDNAME,
		//];

		yield 'ambiguous column in field list' => [
			'query' => 'SELECT id FROM analyser_test a, analyser_test b',
			'error' => "Ambiguous column id",
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];
	}

	/** @dataProvider provideInvalidData */
	public function testInvalid(string $query, string $error, int $dbErrorCode): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db);
		$analyser = new Analyser($parser, $reflection);
		$result = $analyser->analyzeQuery($query);
		$this->assertCount(
			1,
			$result->errors,
			"Expected 1 error. Got: "
			. implode("\n", array_map(static fn (AnalyserError $e) => $e->message, $result->errors)),
		);
		$this->assertSame($error, $result->errors[0]->message);

		try {
			$db->query($query);
			$this->fail('Expected mysqli_sql_exception.');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame($dbErrorCode, $e->getCode());
		}
	}

	/**
	 * @param array<QueryResultField> $expectedFields
	 * @return array<string> without duplicates, in the same order as returned by the query
	 */
	private function getFieldKeys(array $expectedFields): array
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
			MYSQLI_TYPE_NULL => DbTypeEnum::NULL,
			// TODO: MYSQLI_TYPE_ENUM, MYSQLI_TYPE_BIT, MYSQLI_TYPE_INTERVAL, MYSQLI_TYPE_SET,
			// MYSQLI_TYPE_GEOMETRY, MYSQLI_TYPE_JSON, blob/binary types
			default => throw new \RuntimeException("Unhandled type {$type}"),
		};
	}
}
