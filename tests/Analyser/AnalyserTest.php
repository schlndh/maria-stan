<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use DateTimeImmutable;
use MariaStan\Analyser\Exception\DuplicateFieldNameException;
use MariaStan\Analyser\PlaceholderTypeProvider\PlaceholderTypeProvider;
use MariaStan\Analyser\PlaceholderTypeProvider\VarcharPlaceholderTypeProvider;
use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Expr\Placeholder;
use MariaStan\Ast\Query\SelectQueryCombinatorTypeEnum;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\EnumType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\TupleType;
use MariaStan\Schema\DbType\VarcharType;
use MariaStan\TestCaseHelper;
use MariaStan\Util\MariaDbErrorCodes;
use MariaStan\Util\MysqliUtil;
use mysqli_result;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_map;
use function assert;
use function count;
use function implode;
use function in_array;
use function is_array;
use function reset;
use function sprintf;
use function str_starts_with;

use const MYSQLI_ASSOC;
use const MYSQLI_NOT_NULL_FLAG;
use const MYSQLI_NUM;
use const MYSQLI_TYPE_BLOB;
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
	private const IGNORED_WARNINGS
		= [
			MariaDbErrorCodes::ER_TRUNCATED_WRONG_VALUE,
			MariaDbErrorCodes::ER_WARN_DATA_OUT_OF_RANGE,
			MariaDbErrorCodes::ER_DIVISION_BY_ZER,
			// I have a few test-cases with explicit null, which I could detect, but in general I don't
			// want to bother.
			MariaDbErrorCodes::ER_UNKNOWN_LOCALE,
			MariaDbErrorCodes::ER_BAD_DATA,
		];

	/** @return iterable<string, callable(): iterable<string, array<mixed>>> set => fn(): [name => params] */
	public static function getValidTestSets(): iterable
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE analyser_test (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(255) NULL
			);
		");
		$db->query("INSERT INTO analyser_test (id, name) VALUES (1, 'aa'), (2, NULL)");

		$db->query("
			CREATE OR REPLACE TABLE analyse_test_insert (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				val_string_null_no_default VARCHAR(255) NULL,
				val_string_not_null_no_default VARCHAR(255) NOT NULL,
				val_string_null_default VARCHAR(255) NULL DEFAULT 'abc',
				val_string_not_null_default VARCHAR(255) NOT NULL DEFAULT 'def',
				val_enum_null_no_default ENUM('a', 'b') NULL,
				val_enum_null_default ENUM('a', 'b') NULL DEFAULT 'b',
				val_enum_not_null_no_default ENUM('a', 'b') NOT NULL,
				val_enum_not_null_default ENUM('a', 'b') NOT NULL DEFAULT 'b'
			);
		");

		$db->query("
			CREATE OR REPLACE TABLE analyser_test_truncate (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(255) NULL
			);
		");

		$db->query("
			CREATE OR REPLACE TABLE analyser_test_enum (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				enum_abc ENUM ('a', 'b', 'c') NOT NULL,
				enum_ab ENUM ('a', 'b') NOT NULL,
				enum_bc ENUM ('b', 'c') NOT NULL
			);
		");
		$db->query("
			INSERT INTO analyser_test_enum (enum_abc, enum_ab, enum_bc) VALUES ('a', 'b', 'c');
		");

		yield 'misc' => static function (): iterable {
			yield 'SELECT *' => [
				'query' => "SELECT * FROM analyser_test",
			];

			yield 'manually specified columns' => [
				'query' => "SELECT name, id FROM analyser_test",
			];

			yield 'manually specified columns + *' => [
				'query' => "SELECT *, name, id FROM analyser_test",
			];

			yield 'field alias' => [
				'query' => "SELECT 1 id",
			];
		};

		yield 'literal' => self::provideValidLiteralTestData(...);
		yield 'operator' => self::provideValidOperatorTestData(...);
		yield 'data-type' => self::provideValidDataTypeTestData(...);
		yield 'join' => self::provideValidJoinTestData(...);
		yield 'subquery' => self::provideValidSubqueryTestData(...);
		yield 'having' => self::provideValidGroupByHavingOrderTestData(...);
		yield 'placeholder' => self::provideValidPlaceholderTestData(...);
		yield 'functional' => self::provideValidFunctionCallTestData(...);
		yield 'union' => self::provideValidUnionTestData(...);
		yield 'with' => self::provideValidWithTestData(...);
		yield 'insert' => self::provideValidInsertTestData(...);
		yield 'other-query' => self::provideValidOtherQueryTestData(...);
		yield 'update' => self::provideValidUpdateTestData(...);
		yield 'delete' => self::provideValidDeleteTestData(...);
		yield 'table-value-constructor' => self::provideValidTableValueConstructorData(...);
	}

	/** @return iterable<string, array<mixed>> */
	public static function provideValidTestData(): iterable
	{
		foreach (self::getValidTestSets() as $testSet) {
			yield from $testSet();
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidLiteralTestData(): iterable
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
	private static function provideValidDataTypeTestData(): iterable
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$dataTypesTable = 'analyser_test_data_types';
		$db->query("
			CREATE OR REPLACE TABLE {$dataTypesTable} (
				col_int INT NOT NULL,
				col_varchar_null VARCHAR(255) NULL,
				col_decimal DECIMAL(10, 2) NOT NULL,
				col_float FLOAT NOT NULL,
				col_double DOUBLE NOT NULL,
				col_datetime DATETIME NOT NULL,
				col_enum ENUM('a', 'b', 'c') NOT NULL,
				col_uuid UUID NOT NULL,
				col_unsigned INT UNSIGNED NOT NULL
			);
		");
		$db->query("
			INSERT INTO {$dataTypesTable}
				(col_int, col_varchar_null, col_decimal, col_float, col_double, col_datetime, col_uuid, col_unsigned)
			VALUES
			    (1, 'aa', 111.11, 11.11, 1.1, NOW(), UUID(), 5),
			    (2, NULL, 222.22, 22.22, 2.2, NOW(), UUID(), 7)
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

		yield 'column - UUID' => [
			'query' => "SELECT col_uuid FROM {$dataTypesTable}",
		];

		yield 'column - unsigned int' => [
			'query' => "SELECT col_unsigned FROM {$dataTypesTable}",
		];

		$operators = ['-', '+', '!', '~', 'BINARY '];
		$columns = [
			'col_int',
			'col_varchar_null',
			'col_decimal',
			'col_float',
			'col_double',
			'col_datetime',
			'col_uuid',
			'col_unsigned',
		];

		foreach ($operators as $operator) {
			foreach ($columns as $column) {
				// Illegal operations
				// TODO: detect illegal operations on UUID columns
				if ($column === 'col_uuid' && in_array($operator, ['-', '~', '!'], true)) {
					continue;
				}

				yield "unary op: {$operator}{$column}" => [
					'query' => "SELECT {$operator}{$column} FROM {$dataTypesTable}",
				];
			}
		}

		// TODO: name of 2nd column contains comment: SELECT col_int, /*aaa*/ -col_int FROM mysqli_test_data_types
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidJoinTestData(): iterable
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
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

		yield 'CROSS JOIN - comma, parentheses' => [
			'query' => "SELECT * FROM (analyser_test t1, analyser_test t2) JOIN analyser_test t3 ON t1.id = t3.id",
		];

		yield 'CROSS JOIN - explicit' => [
			'query' => "SELECT * FROM analyser_test t1 JOIN analyser_test t2 JOIN analyser_test t3 ON t1.id = t3.id",
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

		yield 'reference column from previous table in ON subquery after UNION - left' => [
			'query' => "
				SELECT 1
				FROM (SELECT 1 aaa) foo
				JOIN (SELECT 1 id) t ON 1 IN (
					SELECT id FROM (SELECT 1 id) bar
					WHERE bar.id = foo.aaa
					UNION
					SELECT 1 as id
				)
			",
		];

		yield 'reference column from previous table in ON subquery after UNION - right' => [
			'query' => "
				SELECT 1
				FROM (SELECT 1 aaa) foo
				JOIN (SELECT 1 id) t ON 1 IN (
					SELECT 1 as id
					UNION
					SELECT id FROM (SELECT 1 id) bar
					WHERE bar.id = foo.aaa
				)
			",
		];

		yield 'reference column from previous table in ON subquery after UNION - 2nd level' => [
			'query' => "
				SELECT 1
				FROM (SELECT 1 aaa) foo
				JOIN (SELECT 1 id) t ON 1 IN (
					SELECT 1 as id
					UNION
					SELECT 2 as id
					UNION
					SELECT id FROM (SELECT 1 id) bar
					WHERE bar.id = foo.aaa
				)
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

		yield 'multiple JOINs - resolve column non-ambiguously vs table joined later' => [
			'query' => "SELECT * FROM (SELECT 1 aa) a JOIN (SELECT 1 bb) b ON aa = bb JOIN (SELECT 1 aa) c",
		];

		yield 'USING - single join' => [
			'query' => "SELECT * FROM (SELECT 1 aa) t1 JOIN (SELECT 1 aa) t2 USING (aa)",
		];

		yield 'USING - single join - t.*' => [
			'query' => "SELECT t1.*, t2.* FROM (SELECT 1 aa) t1 JOIN (SELECT 1 aa) t2 USING (aa)",
		];

		yield 'USING - single join - resolve ambiguity for coalesced column in SELECT' => [
			'query' => "SELECT aa FROM (SELECT 1 aa) t1 JOIN (SELECT 1 aa) t2 USING (aa)",
		];

		yield 'USING - single join - tables' => [
			'query' => "SELECT * FROM analyser_test t1 JOIN analyser_test t2 USING (id)",
		];

		yield 'USING - single join - column order' => [
			'query' => "
				SELECT * FROM (SELECT 1 aa, 2 bb, 3 cc, 4 dd) t1
				JOIN (SELECT 2 bb, 1 aa, 'dd' dd, 'cc' cc) t2 USING (bb, aa)
			",
		];

		yield 'USING - plus another JOIN with ON' => [
			'query' => "SELECT * FROM (SELECT 1 aa) t1 JOIN (SELECT 1 aa) t2 USING (aa) JOIN (SELECT 2 aa) t3 ON 1",
		];

		yield 'USING - multiple tables - explicit JOIN' => [
			'query' => "SELECT * FROM (SELECT 1 aa) t1 JOIN (SELECT 1 bb) t2 JOIN (SELECT 1 aa) t3 USING (aa)",
		];

		yield 'USING - multiple tables - comma with parentheses' => [
			'query' => "SELECT * FROM ((SELECT 1 aa) t1, (SELECT 1 bb) t2) JOIN (SELECT 1 aa) t3 USING (aa)",
		];

		yield 'USING - column order' => [
			'query' => "
				SELECT * FROM
				((SELECT 1 aa) t1, (SELECT 2 bb) t2, (SELECT 3 cc) t3, (SELECT 4 dd) t4)
				JOIN
				((SELECT 2 bb) t5, (SELECT 4 dd) t6, (SELECT 1 aa) t7, (SELECT 3 cc) t8)
				USING (bb, aa)
			",
		];

		yield 'USING - multiple - resolve ambiguity for coalesced column' => [
			'query' => "
				SELECT * FROM
				(SELECT 3 cc, 5 ee, 2 bb) tm
				JOIN
				(
				    ((SELECT 1 aa) t1, (SELECT 2 bb) t2, (SELECT 3 cc) t3, (SELECT 5 ee) t4)
					JOIN
					((SELECT 2 bb) t5, (SELECT 4 dd) t6, (SELECT 1 aa) t7, (SELECT 3 cc) t8)
					USING (bb, aa)
				) USING (bb, ee)
			",
		];

		yield 'USING - column type - non-matching inner' => [
			'query' => "SELECT * FROM (SELECT 1 aa) t1 JOIN (SELECT '1' aa) t2 USING (aa)",
		];

		yield 'USING - column type - non-matching left' => [
			'query' => "SELECT * FROM (SELECT 1 aa) t1 LEFT JOIN (SELECT '2' aa) t2 USING (aa)",
		];

		yield 'USING - column type - non-matching right' => [
			'query' => "SELECT * FROM (SELECT 1 aa) t1 RIGHT JOIN (SELECT '2' aa) t2 USING (aa)",
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidOperatorTestData(): iterable
	{
		$operators = ['+', '-', '*', '/', '%', 'DIV', '<', '='];
		$values = ['1', '1.1', '1e1', '"a"', 'NULL', 'CAST(1 AS UNSIGNED INT)', 'NOW()'];

		foreach ($operators as $op) {
			foreach ($values as $left) {
				foreach ($values as $right) {
					$expr = "{$left} {$op} {$right}";

					// NB: BIGINT value is out of range in 'current_timestamp() * current_timestamp()'
					if ($expr === 'NOW() * NOW()') {
						continue;
					}

					yield "operator {$expr}" => [
						'query' => "SELECT {$expr}",
					];
				}
			}
		}

		foreach ($values as $value1) {
			foreach ($values as $value2) {
				if ($value1 === $value2) {
					continue;
				}

				yield "operator CASE {$value1} vs {$value2}" => [
					'query' => "SELECT CASE WHEN 0 THEN {$value1} ELSE {$value2} END",
				];
			}
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
			'"2022-08-27" - INTERVAL 10 DAY',
			'"2022-08-27" - INTERVAL 10 DAY + INTERVAL 10 DAY',
			'"2022-08-27" - INTERVAL NULL DAY',
			'"aaa" - INTERVAL 10 DAY',
			'NOW() - INTERVAL 10 DAY',
			'1 IN (1, 2)',
			'1 IN (NULL)',
			'1 IN (SELECT * FROM (SELECT id FROM analyser_test LIMIT 10) t)',
			'1 IN (WITH t AS (SELECT * FROM analyser_test LIMIT 10) SELECT id FROM t)',
			'NULL IN (1)',
			'NULL IN (NULL)',
			'(1, 2) IN ((1, NULL))',
			'(1, 2) IN ((1, 2), (1,2))',
			'((1,2), 3) IN (((1,2), 3))',
			'(1, 2) = (3, 4)',
			'(1, 2) != (3, 4)',
			'(1, 2) > (3, 4)',
			'(1, 2) >= (3, 4)',
			'(1, 2) < (3, 4)',
			'(1, 2) <= (3, 4)',
			'(1, 2) <=> (3, 4)',
			'(1,1) = (SELECT 1, 1)',
			'(1,1) IN (SELECT 1, 1)',
			'(1,"aa") IN (SELECT id, name FROM analyser_test)',
			'(SELECT 1) = (SELECT 1)',
			'(SELECT id FROM analyser_test LIMIT 1) = (SELECT id FROM analyser_test LIMIT 1)',
			'(SELECT * FROM analyser_test LIMIT 1) = (SELECT * FROM analyser_test LIMIT 1)',
			'"a" LIKE "b"',
			'"a" LIKE NULL',
			'NULL LIKE "b"',
			'"a" LIKE "b" ESCAPE NULL',
			// TODO: match field name without alias to MariaDB: "c" LIKE "?_" ESCAPE "?"
			'"c" LIKE "😀_" ESCAPE "😀" non_unicode_name',
			'EXISTS (SELECT NULL)',
			'EXISTS (SELECT NULL WHERE 0)',
			'BINARY 1 + 2',
			'BINARY (1 + 2)',
			'BINARY NULL',
			'"a" COLLATE utf8mb4_unicode_ci',
			'name COLLATE utf8mb4_unicode_ci FROM analyser_test',
			'id COLLATE utf8mb4_unicode_ci FROM analyser_test',
		];

		foreach ($exprs as $expr) {
			yield "operator {$expr}" => [
				'query' => "SELECT {$expr}",
			];
		}

		$unaryPlusData = [
			'1',
			'(1)',
			'(1+1)',
			'(+1)',
			'+1',
			'+(-1)',
			'-+1',
			'(SELECT 1)',
			'id',
			'NOW()',
			'"aa"',
			'NULL',
			'1.1',
			'1e1',
		];

		foreach ($unaryPlusData as $value) {
			yield "unary plus prefix of {$value}" => [
				'query' => "SELECT +{$value} FROM analyser_test",
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidSubqueryTestData(): iterable
	{
		yield 'subquery as SELECT expression' => [
			'query' => 'SELECT (SELECT 1)',
		];

		// TODO: is this a mariadb bug? It returns 1
		//yield 'subquery as SELECT expression - LIMIT 0' => [
		//	'query' => 'SELECT (SELECT 1 LIMIT 0)',
		//];

		yield 'subquery as SELECT expression - LIMIT 0' => [
			'query' => 'SELECT (SELECT 1 FROM analyser_test LIMIT 0)',
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

		yield 'subquery as SELECT expression - reference previously aliased field' => [
			'query' => 'SELECT 1 aaa, (SELECT aaa)',
		];

		yield 'subquery - previously aliased field vs column - field list' => [
			'query' => 'SELECT "aa" id, (SELECT id) FROM analyser_test',
		];

		yield 'subquery - previously aliased field vs column - GROUP BY' => [
			'query' => 'SELECT "aa" id FROM analyser_test GROUP BY (SELECT id)',
		];

		yield 'subquery - previously aliased field vs column - WHERE' => [
			'query' => 'SELECT "aa" id FROM analyser_test WHERE (SELECT id) = 1',
		];

		yield 'subquery - previously aliased field vs column - HAVING' => [
			'query' => 'SELECT "aa" id FROM analyser_test HAVING (SELECT id)',
		];

		yield 'subquery - previously aliased field vs column - ORDER BY' => [
			'query' => 'SELECT "aa" id FROM analyser_test ORDER BY (SELECT id)',
		];

		yield 'subquery - reference parent field alias in HAVING' => [
			'query' => 'SELECT 1 aaa HAVING (SELECT aaa) = 1',
		];

		yield 'subquery - reference parent field alias in GROUP BY' => [
			'query' => 'SELECT 1 aaa GROUP BY (SELECT aaa)',
		];

		yield 'subquery - reference parent field alias in ORDER BY' => [
			'query' => 'SELECT 1 aaa ORDER BY (SELECT aaa)',
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

		// TODO: implement this
		//yield 'subquery in FROM - same name as normal table' => [
		//	'query' => 'SELECT * FROM analyser_test, (SELECT 1) analyser_test',
		//];
		//
		//yield 'subquery in FROM - same alias as normal table' => [
		//	'query' => 'SELECT * FROM analyser_test t, (SELECT 1) t',
		//];

		yield 'value IN (subquery)' => [
			'query' => 'SELECT 1 IN (SELECT 1)',
		];

		yield 'use column from two levels up when in SELECT expression' => [
			'query' => 'SELECT 1 IN (SELECT (SELECT id)) a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - UNION' => [
			'query' => 'SELECT 1 IN (SELECT 1 UNION SELECT (SELECT t.id) UNION SELECT 2) a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - tuple' => [
			'query' => 'SELECT 1 IN (0, (SELECT 1 + (SELECT id))) a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - LIKE' => [
			'query' => 'SELECT (SELECT 1 + (SELECT id)) LIKE "x" a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - function call' => [
			'query' => 'SELECT (SELECT COALESCE((SELECT id), 0)) a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - CASE' => [
			'query' => 'SELECT (SELECT CASE (SELECT id) WHEN 1 THEN 2 END) a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - binary op' => [
			'query' => 'SELECT 1 IN (SELECT 1 + (SELECT id)) a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - EXISTS' => [
			'query' => 'SELECT EXISTS(SELECT 1 + (SELECT id)) a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in SELECT expression - COLLATE' => [
			'query' => 'SELECT (SELECT (SELECT id) COLLATE "utf8mb4_unicode_ci") a FROM (SELECT 1 id) t',
		];

		yield 'use column from two levels up when in WHERE' => [
			'query' => 'SELECT * FROM (SELECT 1 id) t WHERE 1 IN (SELECT 1 WHERE (SELECT id))',
		];

		yield 'use column from two levels up when in GROUP BY' => [
			'query' => 'SELECT * FROM (SELECT 1 id) t GROUP BY 1 IN (SELECT 1 GROUP BY (SELECT id))',
		];

		yield 'use column from two levels up when in HAVING' => [
			'query' => 'SELECT * FROM (SELECT 1 id) t HAVING 1 IN (SELECT 1 HAVING (SELECT id))',
		];

		yield 'use column from two levels up when in ORDER BY' => [
			'query' => 'SELECT * FROM (SELECT 1 id) t ORDER BY 1 IN (SELECT 1 ORDER BY (SELECT id))',
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidGroupByHavingOrderTestData(): iterable
	{
		yield 'use alias from field list in GROUP BY' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test GROUP BY aaa',
		];

		// field list wins, non-ambiguous
		yield 'use column in GROUP BY - same alias in fields list vs 2 tables' => [
			'query' => 'SELECT 1 id FROM analyser_test t1, analyser_test t2 GROUP BY id',
		];

		// It doesn't matter so it's not ambiguous
		yield 'use column in GROUP BY - 2x table column' => [
			'query' => 'SELECT id, id id FROM analyser_test GROUP BY id',
		];

		// TODO: Fix this
		//yield 'use column in GROUP BY - non-ambiguous +id in field list' => [
		//	'query' => 'SELECT +id id FROM analyser_test GROUP BY id',
		//];

		yield 'use column in GROUP BY - non-ambiguous collision with parent field list' => [
			'query' => 'SELECT 1 id, (SELECT 5 FROM analyser_test GROUP BY id LIMIT 1)',
		];

		yield 'use column in GROUP BY - resolve column ambiguity via field list' => [
			'query' => 'SELECT t1.id, t2.name FROM analyser_test t1, analyser_test t2 GROUP BY id',
		];

		yield 'use column in GROUP BY - column in fields list vs alias in field list' => [
			'query' => 'SELECT t1.id, 1+1 id FROM analyser_test t1, analyser_test t2 GROUP BY id',
		];

		yield 'use column in GROUP BY - resolve ambiguity by explicit table' => [
			'query' => 'SELECT a.id, b.id FROM analyser_test a, analyser_test b GROUP BY a.id',
		];

		yield 'use column in GROUP BY - resolve ambiguity by explicit table - column alias' => [
			'query' => 'SELECT a.id, b.name id FROM analyser_test a, analyser_test b GROUP BY a.id',
		];

		yield 'use column in GROUP BY - resolve ambiguity by explicit table - *, 1+1 id' => [
			'query' => 'SELECT *, 1+1 id FROM analyser_test a, analyser_test b GROUP BY a.id',
		];

		yield 'use column in GROUP BY - resolve ambiguity by explicit table - a.id, b.id, 1+1 id' => [
			'query' => 'SELECT a.id, b.id, 1+1 id FROM analyser_test a, analyser_test b GROUP BY a.id',
		];

		yield 'use alias from field list in HAVING' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test HAVING aaa > 0',
		];

		yield 'use columns from field list in HAVING - *' => [
			'query' => 'SELECT * FROM analyser_test HAVING name',
		];

		yield 'use columns from field list in HAVING - explicit' => [
			'query' => 'SELECT name FROM analyser_test HAVING name',
		];

		yield 'use columns from field list in HAVING - original name of aliased column' => [
			'query' => 'SELECT name aaa FROM analyser_test HAVING name',
		];

		yield 'use columns from field list in HAVING - table.column' => [
			'query' => 'SELECT name aaa FROM analyser_test HAVING analyser_test.name',
		];

		yield 'use columns from field list in HAVING - table_alias.column' => [
			'query' => 'SELECT name aaa FROM analyser_test t HAVING t.name',
		];

		yield 'use columns from field list in HAVING - original name of aliased column in outer subquery' => [
			'query' => 'SELECT name aaa, (SELECT 1 HAVING name) FROM analyser_test',
		];

		yield 'use columns from GROUP BY in HAVING' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test GROUP BY name HAVING name',
		];

		yield 'use any column in HAVING as part of an aggregate' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test HAVING COUNT(name)',
		];

		yield 'use any column in HAVING as part of an aggregate in a subquery' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test HAVING (SELECT COUNT(name))',
		];

		yield 'use outer subquery column in HAVING' => [
			'query' => 'SELECT id, (SELECT 1 HAVING id > 1) FROM analyser_test',
		];

		yield 'use alias from field list in ORDER BY' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test ORDER BY aaa',
		];

		yield 'ORDER BY - field alias vs column' => [
			'query' => 'SELECT id, 1 id FROM analyser_test ORDER BY id DESC',
		];

		yield 'ORDER BY - field alias vs column - swapped order' => [
			'query' => 'SELECT 1 id, id FROM analyser_test ORDER BY id DESC',
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidPlaceholderTestData(): iterable
	{
		$values = [
			'int' => 1,
			'null' => null,
			'float' => 1.23,
			'string' => 'aaa',
		];

		foreach ($values as $label => $value) {
			yield "bound param - {$label}" => [
				'query' => 'SELECT ? id',
				'params' => [$value],
			];

			yield "bound param - {$label} + 1" => [
				'query' => 'SELECT ? + 1 id',
				'params' => [$value],
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidFunctionCallTestData(): iterable
	{
		$tableName = 'analyser_test';

		$selects = [
			'COUNT all' => "SELECT COUNT(*) FROM {$tableName}",
			'COUNT column' => "SELECT COUNT(id) FROM {$tableName}",
			'COUNT DISTINCT - single column' => "SELECT COUNT(DISTINCT id) FROM {$tableName}",
			'COUNT DISTINCT - multiple columns' => "SELECT COUNT(DISTINCT id, name) FROM {$tableName}",
			'AVG DISTINCT' => "SELECT COUNT(DISTINCT id, name) FROM {$tableName}",
		];

		$dataTypes = [
			'null' => 'null',
			'int' => '5',
			'unsigned int' => 'CAST(5 AS UNSIGNED INT)',
			'decimal' => '5.5',
			'double' => '5.5e1',
			'string' => '"aa"',
			'datetime' => 'NOW()',
		];

		foreach (['AVG', 'MAX', 'MIN', 'SUM', 'GROUP_CONCAT'] as $fn) {
			$selects["{$fn}"] = "SELECT {$fn}(id) FROM {$tableName}";
			$selects["{$fn} WHERE 0"] = "SELECT {$fn}(id) FROM {$tableName} WHERE 0";
			$selects["{$fn} DISTINCT"] = "SELECT {$fn}(DISTINCT id) FROM {$tableName}";

			foreach ($dataTypes as $label => $value) {
				$selects["{$fn}({$label})"] = "SELECT {$fn}({$value})";
			}

			$selects["{$fn}(enum)"] = "SELECT {$fn}(val_enum_null_no_default) FROM analyse_test_insert";
		}

		$nowAliases = ['NOW', 'LOCALtime', 'current_timestamp', 'localtimestamp'];

		foreach ($nowAliases as $nowFn) {
			$selects["{$nowFn}()"] = "SELECT {$nowFn}()";
			$selects["{$nowFn}(precision)"] = "SELECT {$nowFn}(5)";
		}

		foreach ($dataTypes as $label => $value) {
			$selects["DATE_FORMAT({$label}, Y-m)"] = "SELECT DATE_FORMAT({$value}, '%Y-%m')";
			$selects["DATE_FORMAT({$label}, null)"] = "SELECT DATE_FORMAT({$value}, null)";
			$selects["DATE_FORMAT({$label}, Y-m, null)"] = "SELECT DATE_FORMAT({$value}, '%Y-%m', null)";
			$selects["DATE_FORMAT({$label}, Y-m, en_US)"] = "SELECT DATE_FORMAT({$value}, '%Y-%m', 'en_US')";
			$selects["DATE({$label})"] = "SELECT DATE({$value})";
			$selects["YEAR({$label})"] = "SELECT YEAR({$value})";
			$selects["DATE_ADD({$label}, INTERVAL)"] = "SELECT DATE_ADD({$value}, INTERVAL 1 SECOND)";

			// TODO: handle this later when we have better type system.
			if ($label !== 'datetime') {
				$selects["DATE_SUB(NOW(), {$label})"] = "SELECT DATE_SUB(NOW(), INTERVAL {$value} SECOND)";
			}

			$castTargets = [
				'BINARY',
				'CHAR',
				'DATE',
				'DATETIME',
				'DECIMAL',
				'DOUBLE',
				'FLOAT',
				'INTEGER',
				'UNSIGNED INTEGER',
				'TIME',
				'INTERVAL DAY_SECOND(5)',
			];

			foreach ($castTargets as $castTarget) {
				$selects["CAST({$label} AS {$castTarget})"] = "SELECT CAST({$value} AS {$castTarget})";
			}
		}

		foreach ($dataTypes as $label1 => $value1) {
			foreach ($dataTypes as $label2 => $value2) {
				$selects["IF(1, {$label1}, {$label2})"] = "SELECT IF(1, {$value1}, {$value2})";
				$selects["IF(null, {$label1}, {$label2})"] = "SELECT IF(null, {$value1}, {$value2})";
				$selects["TRIM({$label1} FROM {$label2})"] = "SELECT TRIM({$value1} FROM {$value2})";
				$selects["IFNULL({$label1}, {$label2})"] = "SELECT IFNULL({$value1}, {$value2})";
				$selects["NVL({$label1}, {$label2})"] = "SELECT NVL({$value1}, {$value2})";
				$selects["COALESCE({$label1}, {$label2})"] = "SELECT COALESCE({$value1}, {$value2})";
				$selects["COALESCE(NULL, {$label1}, {$label2})"] = "SELECT COALESCE(NULL, {$value1}, {$value2})";
				$selects["COALESCE({$label1}, {$label2}, int)"] = "SELECT COALESCE(NULL, {$value1}, {$value2}, 9)";
				$selects["ROUND({$label1}, {$label2})"] = "SELECT ROUND({$value1}, {$value2})";
				$selects["CONCAT({$label1}, {$label2})"] = "SELECT CONCAT({$value1}, {$value2})";
				$selects["LEAST({$label1}, {$label2})"] = "SELECT LEAST({$value1}, {$value2})";
				$selects["GREATEST({$label1}, {$label2})"] = "SELECT GREATEST({$value1}, {$value2})";
			}

			$selects["TRIM({$label1})"] = "SELECT TRIM({$value1})";
			$selects["COALESCE({$label1})"] = "SELECT COALESCE({$value1})";
			$selects["ROUND({$label1})"] = "SELECT ROUND({$value1})";
			$selects["FLOOR({$label1})"] = "SELECT FLOOR({$value1})";
			$selects["CEIL({$label1})"] = "SELECT CEIL({$value1})";
			$selects["CEILING({$label1})"] = "SELECT CEILING({$value1})";
			$selects["CONCAT({$label1})"] = "SELECT CONCAT({$value1})";
			$selects["LOWER({$label1})"] = "SELECT LOWER({$value1})";
			$selects["LCASE({$label1})"] = "SELECT LCASE({$value1})";
			$selects["UPPER({$label1})"] = "SELECT UPPER({$value1})";
			$selects["UCASE({$label1})"] = "SELECT UCASE({$value1})";
			$selects["ABS({$label1})"] = "SELECT ABS({$value1})";
			$selects["LEAST({$label1}, {$label1}, {$label1})"] = "SELECT LEAST({$value1}, {$value1}, {$value1})";
			$selects["GREATEST({$label1}, {$label1}, {$label1})"] = "SELECT GREATEST({$value1}, {$value1}, {$value1})";
			$selects["FIRST_VALUE({$label1})"] = "SELECT FIRST_VALUE({$value1}) OVER ()";
			$selects["FIRST_VALUE({$label1}) with possibly empty row-set preceding"]
				= "SELECT FIRST_VALUE({$value1}) OVER (ROWS BETWEEN 10 PRECEDING AND 9 PRECEDING)";
			$selects["FIRST_VALUE({$label1}) with possibly empty row-set following"]
				= "SELECT FIRST_VALUE({$value1}) OVER (ROWS BETWEEN 9 FOLLOWING AND UNBOUNDED FOLLOWING)";
		}

		// TODO: figure out the context in which the function is called and adjust the return type accordingly.
		//$selects["VALUE(col) in SELECT"] = "SELECT VALUE(id) FROM analyser_test";
		$selects['CURDATE()'] = "SELECT CURDATE()";
		$selects['CURDATE() + 0'] = "SELECT CURDATE() + 0";
		$selects['CURRENT_DATE()'] = "SELECT CURRENT_DATE()";
		$selects['CURRENT_DATE'] = "SELECT CURRENT_DATE";
		$selects['FOUND_ROWS()'] = "SELECT FOUND_ROWS()";

		foreach ($dataTypes as $label1 => $value1) {
			$selects["REPLACE({$label1}, {$label1}, {$label1})"]
				= "SELECT REPLACE({$value1}, {$value1}, {$value1})";
		}

		$selects["IF(1, CAST(5 AS UNSIGNED INT), IF(0, 1, NULL))"]
			= "SELECT IF(1, CAST(5 AS UNSIGNED INT), IF(0, 1, NULL))";

		foreach ($selects as $label => $select) {
			yield $label => [
				'query' => $select,
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidUnionTestData(): iterable
	{
		foreach (SelectQueryCombinatorTypeEnum::cases() as $combinator) {
			$combinatorVal = $combinator->value;

			yield "{$combinatorVal} - duplicated select" => [
				'query' => "SELECT * FROM analyser_test {$combinatorVal} SELECT * FROM analyser_test",
			];

			yield "{$combinatorVal} - non-matching column names" => [
				'query' => "SELECT 1 id {$combinatorVal} SELECT 2 aa",
			];

			yield "{$combinatorVal} - ORDER BY" => [
				'query' => "SELECT id aa, name FROM analyser_test UNION ALL SELECT * FROM analyser_test ORDER BY aa",
			];

			yield "{$combinatorVal} - ORDER BY bug when in WITH" => [
				'query' => "
					WITH tbl AS (
						SELECT id AS aa, 0 as bb FROM analyser_test
						{$combinatorVal} ALL
						SELECT t.aa, t.bb FROM (SELECT 1 aa, 2 bb) t
						ORDER BY aa, bb
					)
					SELECT * FROM tbl
				",
			];

			$dataTypes = [
				'null' => 'null',
				'int' => '5',
				'unsigned int' => 'CAST(5 as UNSIGNED INT)',
				'decimal' => '5.5',
				'double' => '5.5e1',
				'string' => '"aa"',
				'datetime' => 'NOW()',
			];

			foreach ($dataTypes as $leftLabel => $leftValue) {
				foreach ($dataTypes as $rightLabel => $rightValue) {
					yield "{$combinatorVal} - {$leftLabel} vs {$rightLabel}" => [
						'query' => "SELECT {$leftValue} {$combinatorVal} SELECT {$rightValue}",
					];
				}
			}

			yield "{$combinatorVal} - nullable column vs non-nullable column" => [
				'query' => "SELECT id FROM analyser_test {$combinatorVal} SELECT name FROM analyser_test",
			];

			yield "{$combinatorVal} - use in FROM" => [
				'query' => "SELECT * FROM (SELECT 1 {$combinatorVal} SELECT 2) t",
			];

			yield "{$combinatorVal} - use as subquery expression" => [
				'query' => "SELECT 1 IN (SELECT 1 {$combinatorVal} SELECT 2)",
			];
		}

		yield "UNION - same enum" => [
			'query' => 'SELECT enum_ab FROM analyser_test_enum UNION ALL SELECT enum_ab FROM analyser_test_enum',
			'params' => [],
			'explicit field types' => [
				'enum_ab' => new EnumType(['a', 'b']),
			],
		];

		yield "UNION - different enums" => [
			'query' => 'SELECT enum_ab FROM analyser_test_enum UNION ALL SELECT enum_bc FROM analyser_test_enum',
			'params' => [],
			'explicit field types' => [
				'enum_ab' => new EnumType(['a', 'b', 'c']),
			],
		];

		yield "INTERSECT - same enum" => [
			'query' => 'SELECT enum_ab FROM analyser_test_enum INTERSECT SELECT enum_ab FROM analyser_test_enum',
			'params' => [],
			'explicit field types' => [
				'enum_ab' => new EnumType(['a', 'b']),
			],
		];

		yield "INTERSECT - different enums" => [
			'query' => 'SELECT enum_ab FROM analyser_test_enum INTERSECT SELECT enum_bc FROM analyser_test_enum',
			'params' => [],
			'explicit field types' => [
				'enum_ab' => new EnumType(['b']),
			],
		];

		yield "EXCEPT - same enum" => [
			'query' => 'SELECT enum_ab FROM analyser_test_enum EXCEPT SELECT enum_ab FROM analyser_test_enum',
			'params' => [],
			'explicit field types' => [
				'enum_ab' => new EnumType(['a', 'b']),
			],
		];

		yield "EXCEPT - different enums" => [
			'query' => 'SELECT enum_ab FROM analyser_test_enum EXCEPT SELECT enum_bc FROM analyser_test_enum',
			'params' => [],
			'explicit field types' => [
				'enum_ab' => new EnumType(['a', 'b']),
			],
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidWithTestData(): iterable
	{
		yield "WITH" => [
			'query' => "WITH tbl AS (SELECT * FROM analyser_test) SELECT * FROM tbl",
		];

		yield "WITH - multiple CTEs" => [
			'query' => "WITH tbl AS (SELECT * FROM analyser_test), tbl2 AS (SELECT 1 aaa) SELECT * FROM tbl, tbl2",
		];

		yield "WITH - multiple CTEs - reference previous CTE" => [
			'query' => "WITH tbl AS (SELECT * FROM analyser_test), tbl2 AS (SELECT * FROM tbl) SELECT * FROM tbl, tbl2",
		];

		yield "WITH - explicit column list" => [
			'query' => "WITH tbl (aa, bb) AS (SELECT id, name FROM analyser_test) SELECT * FROM tbl",
		];

		yield "WITH - alias on SELECT" => [
			'query' => "WITH tbl AS (SELECT * FROM analyser_test) SELECT aaa.id FROM tbl aaa",
		];

		yield "WITH - alias on SELECT - same as CTE name" => [
			'query' => "WITH tbl AS (SELECT * FROM analyser_test) SELECT tbl.id FROM tbl tbl",
		];

		yield "WITH - alias on SELECT - multiple" => [
			'query' => "WITH tbl AS (SELECT * FROM analyser_test) SELECT * FROM tbl aaa, tbl bbb",
		];

		yield "WITH - alias on SELECT - multiple - * from one" => [
			'query' => "WITH tbl AS (SELECT * FROM analyser_test) SELECT bbb.* FROM tbl aaa, tbl bbb",
		];

		yield "WITH - CTE name overshadows existing table" => [
			'query' => "WITH analyser_test AS (SELECT 1) SELECT * FROM analyser_test",
		];

		yield "WITH - subquery with WITH and same CTE name" => [
			'query' => "
				WITH tbl AS (SELECT 1 id)
				SELECT * FROM (
					WITH tbl AS (SELECT 2 id)
					SELECT * FROM tbl
				) t, tbl
			",
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidTableValueConstructorData(): iterable
	{
		yield 'TVC - simple WITH' => [
			'query' => "WITH tbl AS (VALUES (1, 2), (3, 4)) SELECT * FROM tbl",
		];

		yield 'TVC - simple WITH - column names' => [
			'query' => "WITH tbl (a, b) AS (VALUES (1, 2), (3, 4)) SELECT * FROM tbl",
		];

		yield 'TVC - simple WITH - combined types' => [
			'query' => "WITH tbl (a, b) AS (VALUES (1, 'a'), ('b', null)) SELECT * FROM tbl",
		];

		yield 'TVC - subquery' => [
			'query' => "SELECT * FROM (VALUES (1 + 1, 2), (3, 4)) t",
		];

		foreach (SelectQueryCombinatorTypeEnum::cases() as $combinator) {
			yield 'TVC - ' . $combinator->value => [
				'query' => "SELECT id, id FROM analyser_test {$combinator->value} VALUES (1, 1)",
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidOtherQueryTestData(): iterable
	{
		yield "TRUNCATE TABLE" => [
			'query' => 'TRUNCATE TABLE analyser_test_truncate',
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidInsertTestData(): iterable
	{
		foreach (['INSERT', 'REPLACE'] as $type) {
			yield "{$type} ... SET, explicitly set id" => [
				'query' => $type . ' INTO analyser_test SET id = 999, name = "abcd"',
			];

			yield "{$type} ... VALUES, explicitly set id" => [
				'query' => $type . ' INTO analyser_test VALUES (999, "abcd")',
			];

			yield "{$type} ... SELECT, explicitly set id" => [
				'query' => $type . ' INTO analyser_test SELECT 999, "abcd"',
			];

			yield "{$type} ... SELECT ... UNION" => [
				'query' => $type . ' INTO analyser_test SELECT 999, "abcd" UNION SELECT 998, "aaa"',
			];

			yield "{$type} ... SELECT ... WITH" => [
				'query' => $type . ' INTO analyser_test WITH tbl AS (SELECT 999, "abcd") SELECT * FROM tbl',
			];

			yield "{$type} ... SET, skip column with default value" => [
				'query' => $type . ' INTO analyse_test_insert SET val_string_not_null_no_default = "aaa"',
			];

			yield "{$type} ... VALUES, skip column with default value" => [
				'query' => $type . ' INTO analyse_test_insert (val_string_not_null_no_default) VALUES ("abcd")',
			];

			yield "{$type} ... SELECT, skip column with default value" => [
				'query' => $type . ' INTO analyse_test_insert (val_string_not_null_no_default) SELECT "abcd"',
			];
		}

		yield 'INSERT ... ON DUPLICATE KEY UPDATE' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default) SELECT 1, "abcd"
				ON DUPLICATE KEY UPDATE id = id
			',
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - VALUES(col)' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default) SELECT 1, "abcd"
				ON DUPLICATE KEY UPDATE val_string_not_null_no_default = VALUES(val_string_not_null_no_default)
			',
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - VALUE(col)' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default) SELECT 1, "abcd"
				ON DUPLICATE KEY UPDATE val_string_not_null_no_default = VALUE(val_string_not_null_no_default)
			',
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - placeholder' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default) SELECT 1, "abcd"
				ON DUPLICATE KEY UPDATE val_string_not_null_no_default = ?
			',
			'params' => ['aaa'],
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - reference column from SELECT - subquery' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default)
				SELECT * FROM (
				    SELECT 1 new_id, "abcd" name
				) t
				ON DUPLICATE KEY UPDATE id = new_id, val_string_not_null_no_default = t.name
			',
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - reference column from SELECT - table' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default)
				SELECT id, "abcd" FROM analyser_test
				ON DUPLICATE KEY UPDATE id = analyse_test_insert.id
			',
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - almost ambiguous' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default)
				SELECT 1, "aa" FROM analyse_test_insert
				ON DUPLICATE KEY UPDATE analyse_test_insert.id = 5;
			',
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - ambiguity resolved via VALUE' => [
			'query' => '
				INSERT INTO analyse_test_insert
				SELECT * FROM analyse_test_insert
				ON DUPLICATE KEY UPDATE id = VALUE(id);
			',
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - ambiguity resolved via VALUES' => [
			'query' => '
				INSERT INTO analyse_test_insert
				SELECT * FROM analyse_test_insert
				ON DUPLICATE KEY UPDATE id = VALUES(id);
			',
		];
		// TODO: DEFAULT expression
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidUpdateTestData(): iterable
	{
		yield 'UPDATE - single table' => [
			'query' => 'UPDATE analyser_test SET name = "aaa" WHERE id = 5 ORDER BY id LIMIT 5',
		];

		yield 'UPDATE - single table - table.column' => [
			'query' => '
				UPDATE analyser_test SET analyser_test.name = "aaa" WHERE analyser_test.id = 5
				ORDER BY analyser_test.id LIMIT 5
			',
		];

		yield 'UPDATE - single table - alias.column' => [
			'query' => 'UPDATE analyser_test t SET t.name = "aaa" WHERE t.id = 5 ORDER BY t.id LIMIT 5',
		];

		yield 'UPDATE - multi-table - update both tables' => [
			'query' => 'UPDATE analyser_test t, analyser_test t2 SET t.name = t2.name, t2.name = t.name',
		];

		yield 'UPDATE - multi-table - subquery' => [
			'query' => 'UPDATE analyser_test t, (SELECT * FROM analyser_test) t2 SET t.name = t2.name',
		];

		yield 'UPDATE - reference previous column value' => [
			'query' => 'UPDATE analyser_test SET name = CASE name WHEN "aa" THEN "bb" ELSE name END',
		];

		yield 'UPDATE - same source and target' => [
			'query' => 'UPDATE analyser_test SET name = (SELECT name FROM analyser_test ORDER BY id LIMIT 1)',
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidDeleteTestData(): iterable
	{
		yield 'DELETE - single table' => [
			'query' => 'DELETE FROM analyser_test_truncate WHERE id > 5 ORDER BY id, name DESC LIMIT 5',
		];

		yield 'DELETE - placeholders' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1 JOIN (SELECT 1 id) t2 ON ? WHERE t1.id > ?',
			'params' => [1, 2],
		];

		yield 'DELETE - same source and target' => [
			'query' => 'DELETE FROM analyser_test_truncate WHERE id IN (SELECT id FROM analyser_test_truncate)',
		];

		yield 'DELETE - by alias' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1',
		];

		yield 'DELETE - multi-table without aliases' => [
			'query' => 'DELETE analyser_test_truncate FROM analyser_test_truncate, analyser_test',
		];
	}

	/**
	 * @dataProvider provideValidTestData
	 * @param array<scalar|null> $params
	 * @param array<string, DbType> $explicitFieldTypes
	 */
	public function testValid(string $query, array $params = [], array $explicitFieldTypes = []): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$analyser = TestCaseHelper::createAnalyser();
		$result = $analyser->analyzeQuery($query, new VarcharPlaceholderTypeProvider());
		$isUnhanhledExpressionTypeError = static fn (AnalyserError $e) => str_starts_with(
			$e->message,
			'Unhandled expression type',
		);
		$unhandledExpressionTypeErrors = array_filter($result->errors, $isUnhanhledExpressionTypeError);
		$otherErrors = array_filter(
			$result->errors,
			static fn (AnalyserError $e) => ! $isUnhanhledExpressionTypeError($e),
		);

		$this->assertCount(
			0,
			$otherErrors,
			"Expected no errors. Got: "
			. implode("\n", array_map(static fn (AnalyserError $e) => $e->message, $otherErrors)),
		);
		$this->assertSame(count($params), $result->positionalPlaceholderCount);
		$db->begin_transaction();

		try {
			$stmt = null;

			if (count($params) > 0) {
				$stmt = $db->prepare($query);
				$stmt->execute($params);
				$dbResult = $stmt->get_result();
			} else {
				$dbResult = $db->query($query);
			}

			$warningArr = $this->getRelevantWarnings($db);

			if ($dbResult instanceof mysqli_result) {
				$fields = $dbResult->fetch_fields();
				$rows = $dbResult->fetch_all(MYSQLI_ASSOC);
				$dbResult->close();
			} else {
				$fields = [];
				$rows = [];
			}

			$stmt?->close();
		} finally {
			$db->rollback();
		}

		if (count($warningArr) > 0) {
			$this->fail("Warnings: " . implode("\n", $warningArr));
		}

		$this->assertNotNull($result->resultFields);
		$fieldKeys = $this->getFieldKeys($result->resultFields);
		$this->assertSameSize($result->resultFields, $fields);
		$forceNullsForColumns = [];
		$unnecessaryNullableFields = [];
		$datetimeFields = [];
		$mixedFieldErrors = [];
		$this->assertNotNull($result->resultFields);

		for ($i = 0; $i < count($fields); $i++) {
			$field = $fields[$i];
			$analyzedField = $result->resultFields[$i];
			$analyzedExprType = $analyzedField->exprType;
			$this->assertSame($analyzedField->name, $field->name);
			$isFieldNullable = ($field->flags & MYSQLI_NOT_NULL_FLAG) === 0;

			if ($analyzedExprType->isNullable && ! $isFieldNullable) {
				$unnecessaryNullableFields[] = $analyzedField->name;
			} elseif (! $analyzedExprType->isNullable && $isFieldNullable) {
				$forceNullsForColumns[$field->name] = false;
			}

			$actualType = $this->mysqliTypeToDbTypeEnum($field->type, $field->flags);

			// It seems that in some cases the type returned by the database does not propagate NULL in all cases.
			// E.g. 1 + NULL is double for some reason. Let's allow the analyser to get away with null, but force
			// check that the returned values are all null.
			if ($analyzedExprType->type::getTypeEnum() === DbTypeEnum::NULL && $field->type !== MYSQLI_TYPE_NULL) {
				$forceNullsForColumns[$field->name] = true;
			} elseif ($analyzedExprType->type::getTypeEnum() === DbTypeEnum::ENUM) {
				// MYSQLI_ENUM_FLAG can get hidden by functions like MAX/MIN
				$this->assertSame(DbTypeEnum::VARCHAR, $actualType);
			} elseif ($analyzedExprType->type::getTypeEnum() === DbTypeEnum::DATETIME) {
				if ($actualType === DbTypeEnum::VARCHAR) {
					$datetimeFields[] = $i;
				} else {
					$this->assertSame($actualType->value, $analyzedExprType->type::getTypeEnum()->value);
				}
			} elseif ($analyzedExprType->type::getTypeEnum() === DbTypeEnum::MIXED) {
				$mixedFieldErrors[] = "DB type for {$analyzedField->name} should be {$actualType->value} got MIXED.";
			} else {
				$this->assertSame(
					$analyzedExprType->type::getTypeEnum(),
					$actualType,
					"The test says {$analyzedField->name} should be {$analyzedExprType->type::getTypeEnum()->name} "
					. "but got {$actualType->name} from the database.",
				);
			}

			if ($field->table === '') {
				$this->assertNull($analyzedExprType->column);
			} else {
				$this->assertNotNull($analyzedExprType->column);
				$column = $analyzedExprType->column;

				$this->assertSame($field->table, $column->tableAlias);
				$this->assertSame($field->orgname, $column->name);

				if ($field->orgtable === '') {
					$this->assertSame(ColumnInfoTableTypeEnum::SUBQUERY, $column->tableType);
				} elseif (
					$field->orgtable !== $field->table
					// For CTE we have the original name in tableName even if it's later aliased in FROM.
					|| $column->tableType !== ColumnInfoTableTypeEnum::SUBQUERY
				) {
					$this->assertSame($field->orgtable, $column->tableName);
				}
			}
		}

		foreach ($rows as $row) {
			$this->assertIsArray($row);
			$this->assertSame($fieldKeys, array_keys($row));

			foreach ($forceNullsForColumns as $col => $mustBeNull) {
				if ($mustBeNull) {
					$this->assertNull(
						$row[$col],
						'Analyser thinks that column is always NULL, but it contains non NULL value.',
					);
				} else {
					$this->assertNotNull(
						$row[$col],
						'Analyser thinks that column is not nullable, but it contains null.',
					);
				}
			}

			$colNames = array_keys($row);

			foreach ($datetimeFields as $colIdx) {
				$val = $row[$colNames[$colIdx]];

				if ($val === null) {
					continue;
				}

				$parsedDateTime = false;
				$this->assertIsString($val);

				foreach (['Y-m-d H:i:s', 'Y-m-d'] as $format) {
					$parsedDateTime = $parsedDateTime
						?: DateTimeImmutable::createFromFormat($format, $val);
				}

				$this->assertNotFalse($parsedDateTime);
			}

			$i = 0;

			foreach ($row as $value) {
				$analyzedField = $result->resultFields[$i];
				$analyzedExprType = $analyzedField->exprType;
				$i++;

				if ($analyzedExprType->type::getTypeEnum() !== DbTypeEnum::ENUM) {
					continue;
				}

				if ($analyzedExprType->isNullable && $value === null) {
					continue;
				}

				assert($analyzedExprType->type instanceof EnumType);
				$this->assertContains($value, $analyzedExprType->type->cases);
			}
		}

		$analyserFieldNameMap = [];

		foreach ($result->resultFields ?? [] as $field) {
			$analyserFieldNameMap[$field->name][] = $field;
		}

		foreach ($explicitFieldTypes as $column => $expectedType) {
			$this->assertArrayHasKey($column, $analyserFieldNameMap);
			$fields = $analyserFieldNameMap[$column];
			$this->assertCount(1, $fields);
			$field = reset($fields);
			$this->assertInstanceOf(QueryResultField::class, $field);
			$type = $field->exprType->type;
			$this->assertEquals($expectedType, $type);
		}

		$incompleteTestErrors = [];

		// With bound params mysqli has the advantage of knowing the values, which we don't. So let's allow it to be
		// nullable.
		if (count($unnecessaryNullableFields) > 0 && count($params) === 0) {
			$incompleteTestErrors[] = "These fields don't have to be nullable:\n"
				. implode(",\n", $unnecessaryNullableFields);
		}

		if (count($unhandledExpressionTypeErrors) > 0) {
			$incompleteTestErrors[] = "There are unhandled expression types:\n"
				. implode(
					",\n",
					array_map(static fn (AnalyserError $e) => $e->message, $unhandledExpressionTypeErrors),
				);
		}

		if (count($mixedFieldErrors) > 0) {
			$incompleteTestErrors[] = 'Some fields are MIXED: ' . implode("\n", $mixedFieldErrors);
		}

		if (count($incompleteTestErrors) > 0) {
			$this->markTestIncomplete(implode("\n-----------\n", $incompleteTestErrors));
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidNullabilityOperatorTestData(): iterable
	{
		$operations = [
			'col_vchar IS NOT NULL',
			'col_vchar IS NULL',
			'NOT col_vchar',
			'col_vchar',
			'col_vchar IS NOT TRUE',
			'col_vchar IS NOT FALSE',
			'(! col_vchar) IS NULL',
			'(! col_vchar) IS NOT NULL',
			'NULL IS NOT NULL OR col_vchar IS NOT NULL',
			'! (NULL IS NULL AND col_vchar IS NULL)',
			'col_vchar = "aa"',
			'col_vchar != ""',
			'col_vchar > ""',
			'col_vchar >= ""',
			'col_vchar < "zz"',
			'col_vchar <= "zz"',
			// TODO: implement this
			//'col_vchar BETWEEN "" AND "zz"',
			//'col_vchar NOT BETWEEN "zz" AND "zzz"',
			'col_vchar IN ("aa")',
			'col_vchar IN ("aa", NULL)',
			'col_vchar IN (SELECT col_vchar FROM analyser_test_nullability_1)',
			'(col_vchar IN ("aa")) IS NULL',
			'(col_vchar IN ("aa")) IS NOT NULL',
			'(col_vchar IN ("aa", NULL)) IS NULL',
			'(col_vchar IN ("aa", NULL)) IS NOT NULL',
			'col_vchar NOT IN ("aa")',
			'(col_vchar NOT IN ("aa", NULL)) IS NULL',
			'col_vchar NOT IN (SELECT col_vchar FROM analyser_test_nullability_1 WHERE id = 2)',
			'~(col_vchar IS NULL)',
			'~(col_vchar IS NOT NULL)',
			'col_vchar AND DATE(NOW())',
			'col_vchar OR DATE(NOW())',
			'col_vchar COLLATE utf8mb4_unicode_ci IS NOT NULL',
		];

		foreach (['+', '-', '~', 'BINARY'] as $unaryOp) {
			$operations[] = "{$unaryOp} col_vchar";
			$operations[] = "! ({$unaryOp} col_vchar)";
			$operations[] = "({$unaryOp} col_vchar) IS NULL";
			$operations[] = "({$unaryOp} col_vchar) IS NOT NULL";
		}

		$arithmeticOperators = ['+', '-', '*', '/', 'DIV', '%', '<<', '>>'];
		$divOperators = ['/', 'DIV', '%'];

		foreach ($arithmeticOperators as $op) {
			$operations[] = "(col_vchar {$op} 5) IS NOT NULL";
			$operations[] = "(5 {$op} col_vchar) IS NOT NULL";

			if (in_array($op, $divOperators, true)) {
				continue;
			}

			$operations[] = "(col_vchar {$op} 1) IS NULL";
			$operations[] = "(5 {$op} col_vchar) IS NULL";
		}

		$falseValues = ['0', '0.0', '0.0e1', '""', '"a"', '"0.0"', '"0.0e1"'];

		foreach ($falseValues as $value) {
			$operations[] = "{$value} OR col_vchar IS NOT NULL";
		}

		$trueValues = ['1', '0.1', '0.1e1', '"1"', '"0.1"', '"0.1e1"'];

		foreach ($trueValues as $value) {
			$operations[] = "! ({$value} AND col_vchar IS NOT NULL)";
		}

		$intervalValues = ['1', 'NULL', '0.0', '"aa"', '"2023-02-10"'];

		foreach ($intervalValues as $value) {
			$operations[] = "(col_vchar + INTERVAL {$value} DAY) IS NULL";

			if ($value === 'NULL') {
				continue;
			}

			$operations[] = "(col_vchar + INTERVAL {$value} DAY) IS NOT NULL";
		}

		foreach ($operations as $op) {
			yield "SELECT * WHERE {$op}" => [
				'query' => "SELECT * FROM analyser_test_nullability_1 WHERE {$op}",
			];
		}

		$operations = [
			'col_vchar <=> col_int',
			'col_int = 1',
			't1.col_vchar IN (t2.col_int, 1)',
			'COALESCE(col_vchar)',
			'COALESCE(col_vchar IS NULL)',
			'COALESCE(NULL, NULL, col_vchar)',
			'CONCAT(col_vchar)',
			'CONCAT(col_vchar, NULL) IS NULL',
			'CONCAT(col_vchar) IS NOT NULL',
			'CONCAT(col_vchar, col_int) IS NOT NULL',
			'CONCAT(col_vchar, col_int) IS NULL',
			'LOWER(col_vchar)',
			'NOT LCASE(col_vchar)',
			'UPPER(col_vchar) IS NULL',
			'UCASE(col_vchar) IS NOT NULL',
			'YEAR(col_vchar) IS NOT NULL',
			'YEAR(col_vchar) IS NULL',
			'ABS(col_int) IS NULL',
			'ABS(col_int) IS NOT NULL',
			'ABS(col_int)',
			'NOT ABS(col_int)',
			'LEAST(col_int, col_vchar) IS NOT NULL',
			// TODO: Enable this after 10.11.8. https://jira.mariadb.org/browse/MDEV-21034
			//'LEAST(col_int, col_vchar)',
			'NOT LEAST(col_int, col_vchar)',
			'GREATEST(col_int, col_vchar) IS NULL',
		];

		foreach (['COALESCE', 'IFNULL', 'NVL'] as $coalesceFn) {
			$operations[] = "{$coalesceFn}(col_vchar, col_int) IS NULL";
			$operations[] = "{$coalesceFn}(col_vchar, NULL) IS NOT NULL";
			$operations[] = "{$coalesceFn}(NULL, col_vchar) IS NOT NULL";
			$operations[] = "{$coalesceFn}(NULL, col_vchar) IS NULL";
			$operations[] = "{$coalesceFn}(NULL, col_vchar)";
			$operations[] = "NOT {$coalesceFn}(NULL, col_vchar)";
		}

		foreach (['AND', 'OR', '&', '|'] as $logOp) {
			$operations[] = "col_vchar {$logOp} col_int";

			// TODO: implement this later. For now, I'm limited by the fact that TRUTHY & TRUTHY may be 0 (e.g. 2&4).
			// In this case it is known statically that both of the arguments are either 0 or 1, so & works like AND,
			// but I can't get that information yet.
			// The FALSY alternative of this is something like NOT ((col_vchar IS NULL) & true).
			if ($logOp !== '&') {
				$operations[] = "(col_vchar IS NULL) {$logOp} (col_int IS NOT NULL)";
			}

			foreach (['NOT', '~'] as $notOp) {
				$operations[] = "{$notOp} (col_vchar {$logOp} col_int)";
				$operations[] = "{$notOp} ((col_vchar IS NULL) {$logOp} (col_int IS NOT NULL))";
			}
		}

		foreach (['XOR', '^'] as $xorOp) {
			$operations[] = "col_vchar {$xorOp} col_int";
			$operations[] = "! (col_vchar {$xorOp} col_int)";
			$operations[] = "(col_vchar {$xorOp} col_int) IS NULL";
			$operations[] = "(col_vchar {$xorOp} col_int) IS NOT NULL";
		}

		foreach ($divOperators as $op) {
			$operations[] = "(t1.col_vchar {$op} 0) IS NOT NULL OR col_int IS NULL";
			$operations[] = "! ((t1.col_vchar {$op} 0) IS NULL AND col_int IS NULL)";
			$operations[] = "(1 {$op} t1.col_vchar) IS NULL";
		}

		foreach ($arithmeticOperators as $op) {
			$operations[] = "t1.col_vchar {$op} (t2.col_int + 5)";
		}

		foreach ($operations as $op) {
			yield "t1 CROSS JOIN t2 WHERE {$op}" => [
				'query' => "
					SELECT * FROM analyser_test_nullability_1 t1, analyser_test_nullability_2 t2
					WHERE {$op}
				",
			];
		}

		$placeholderOperations = [
			['col_vchar = ?', ['1']],
			['col_vchar != ?', ['1']],
			['col_vchar > ?', ['1']],
			['col_vchar >= ?', ['1']],
			['col_vchar < ?', ['aa']],
			['col_vchar <= ?', ['1']],
			['col_vchar + ?', ['1']],
			['col_vchar - ?', ['1']],
			['col_vchar * ?', ['1']],
			['col_vchar / ?', ['1']],
			['col_vchar MOD ?', ['2']],
			['? IS NULL', [null]],
		];

		foreach ($placeholderOperations as [$op, $params]) {
			yield "placeholder: {$op}" => [
				'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE ' . $op,
				'params' => $params,
			];
		}

		$operations = [
			't1.col_vchar = t2.col_vchar',
			't1.col_vchar != t2.col_vchar',
			't1.col_vchar > t2.col_vchar',
			't1.col_vchar >= t2.col_vchar',
			't1.col_vchar < t2.col_vchar',
			't1.col_vchar <= t2.col_vchar',
			// TODO: implement this
			//'t1.col_vchar BETWEEN t2.col_vchar AND t2.col_vchar',
			//'t1.col_vchar NOT BETWEEN t2.col_vchar AND t2.col_vchar',
			't1.col_vchar IN (t2.col_vchar, NULL, 1)',
			't1.col_vchar IN (t2.col_vchar, 1)',
			't1.col_vchar NOT IN (t2.col_vchar, 1)',
			'(t1.col_vchar NOT IN (t2.col_vchar, NULL)) IS NULL',
			'REPLACE(t1.col_vchar, "value not contained in col_vchar", t2.col_vchar) IS NOT NULL',
			'REPLACE(t1.col_vchar, t2.col_vchar, "a") IS NOT NULL',
			'REPLACE(t1.col_vchar, t1.col_vchar, t1.col_vchar) IS NULL',
			'REPLACE(t1.col_vchar, "a", "b") IS NULL',
			'REPLACE("a", t1.col_vchar, "b") IS NULL',
			'REPLACE("a", "b", t1.col_vchar) IS NULL',
			'REPLACE(t1.col_vchar, t2.col_vchar, "b") IS NULL',
			'REPLACE(t1.col_vchar, t2.col_vchar, NULL) IS NULL',
		];

		foreach ($operations as $op) {
			yield "t1 CROSS JOIN t1 WHERE {$op}" => [
				'query' => "
					SELECT * FROM analyser_test_nullability_1 t1, analyser_test_nullability_1 t2
					WHERE {$op}
				",
			];
		}

		$operations = [
			'1 = NULL',
			'1 <=> NULL',
		];

		foreach ($operations as $op) {
			yield "SELECT {$op}" => [
				'query' => "SELECT {$op}",
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidNullabilityMiscTestData(): iterable
	{
		yield 'no WHERE' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1',
		];

		yield 'useless WHERE 1' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE 1',
		];

		yield 'useless WHERE id IS NOT NULL' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE id IS NOT NULL',
		];

		yield 'nullability narrowing works with SELECT *' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE col_vchar IS NOT NULL',
		];

		yield 'nullability narrowing works with SELECT t.*' => [
			'query' => 'SELECT t.* FROM analyser_test_nullability_1 t WHERE col_vchar IS NOT NULL',
		];

		yield 'nullability narrowing works with SELECT col_vchar' => [
			'query' => 'SELECT col_vchar FROM analyser_test_nullability_1 WHERE col_vchar IS NOT NULL',
		];

		yield 'nullability narrowing works with SELECT * FROM (SELECT col_vchar)' => [
			'query' => '
				SELECT * FROM (SELECT col_vchar FROM analyser_test_nullability_1 WHERE col_vchar IS NOT NULL) t
			',
		];

		yield 'CROSS JOIN same table, one col is NOT NULL' => [
			'query' => '
				SELECT * FROM analyser_test_nullability_1 t1, analyser_test_nullability_1 t2
				WHERE t1.col_vchar IS NOT NULL
			',
		];

		yield 'CROSS JOIN subquery with alias = table name, one col is NOT NULL' => [
			'query' => '
				SELECT * FROM analyser_test_nullability_1 t1
				CROSS JOIN (SELECT "a" col_vchar UNION SELECT NULL) analyser_test_nullability_1
				WHERE analyser_test_nullability_1.col_vchar IS NOT NULL
			',
		];

		yield 'col from parent of subquery is NOT NULL' => [
			'query' => 'SELECT *, (SELECT 1 WHERE col_vchar IS NOT NULL) FROM analyser_test_nullability_1',
		];

		yield 'always true: WHERE NOT (0 AND col_vchar)' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE NOT (0 AND col_vchar)',
		];

		yield 'always true: WHERE 1 OR col_vchar' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE 1 OR col_vchar',
		];

		yield 'useless part of condition: AND' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE id IS NOT NULL AND col_vchar IS NOT NULL',
		];

		yield 'useless part of condition: NOT AND' => [
			'query' => 'SELECT * FROM analyser_test_nullability_1 WHERE NOT (id IS NOT NULL AND col_vchar IS NOT NULL)',
		];

		$irrelevantTrueConditions = [
			'NOW() - INTERVAL 2 MONTH < NOW()',
			'NOW() IS NOT NULL',
			'CURDATE() IS NOT NULL',
		];

		foreach ($irrelevantTrueConditions as $condition) {
			$where = "col_vchar IS NOT NULL AND ({$condition})";

			yield $where => [
				'query' => "SELECT * FROM analyser_test_nullability_1 WHERE {$where}",
			];

			$where = "NOT (col_vchar IS NULL OR NOT ({$condition}))";

			yield $where => [
				'query' => "SELECT * FROM analyser_test_nullability_1 WHERE {$where}",
			];
		}

		yield 'JOIN' => [
			'query' => '
				SELECT * FROM analyser_test_nullability_1 t1
				JOIN analyser_test_nullability_1 t2 ON t1.col_vchar = t2.col_vchar
			',
		];

		yield 'LEFT JOIN' => [
			'query' => '
				SELECT * FROM analyser_test_nullability_1 t1
				LEFT JOIN analyser_test_nullability_1 t2 ON t1.col_vchar = t2.col_vchar
			',
		];

		yield 'RIGHT JOIN' => [
			'query' => '
				SELECT * FROM analyser_test_nullability_1 t1
				RIGHT JOIN analyser_test_nullability_1 t2 ON t1.col_vchar = t2.col_vchar
			',
		];

		yield 'LEFT JOIN (JOIN)' => [
			'query' => '
				SELECT * FROM analyser_test_nullability_1 t1
				LEFT JOIN (
				    analyser_test_nullability_1 t2
				    JOIN analyser_test_nullability_1 t3 ON t2.col_vchar = t3.col_vchar
				) ON t2.col_vchar = t1.col_vchar
			',
		];

		// TODO: fix this
		//yield 'JOIN USING' => [
		//	'query' => '
		//		SELECT * FROM analyser_test_nullability_1 t1
		//		JOIN analyser_test_nullability_1 t2 USING (col_vchar)
		//	',
		//];
		//
		//yield 'JOIN USING - nested' => [
		//	'query' => '
		//		SELECT * FROM (analyser_test_nullability_1 t1 JOIN analyser_test_nullability_1 t2 USING (col_vchar))
		//		JOIN analyser_test_nullability_1 r3 USING (col_vchar)
		//	',
		//];

		// TODO: fix this
		//yield 'WHERE 0 ORDER BY COUNT(*)' => [
		//	'query' => 'SELECT id FROM analyser_test WHERE 0 ORDER BY COUNT(*)',
		//];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideValidNullabilityGroupByTestData(): iterable
	{
		// TODO: add /, % and DIV once we can remove nullability for "/ 1" etc.
		$binaryOps = ['+', '-', '*'];
		$unaryOps = ['+', '-', '~', 'BINARY'];

		foreach (['AVG', 'MAX', 'MIN', 'SUM', 'GROUP_CONCAT'] as $fn) {
			$aggFnCalls = [
				"{$fn}(not-null)" => [
					"{$fn}(a2.id)",
					'SELECT %s FROM analyser_test a1, analyser_test a2 GROUP BY a1.id',
				],
				"{$fn}(nullable)" => [
					"{$fn}(a3.name)",
					'
						SELECT %s
						FROM (SELECT a1.* FROM analyser_test a1, analyser_test a2) t
						JOIN analyser_test a3 ON a3.id = t.id
						GROUP BY a3.id
					',
				],
			];

			foreach ($aggFnCalls as $labelPrefix => [$expr, $queryTemplate]) {
				yield $labelPrefix . ' GROUP BY' => [
					'query' => sprintf($queryTemplate, $expr),
				];

				yield "SELECT * FROM (SELECT {$labelPrefix} GROUP BY) t" => [
					'query' => 'SELECT * FROM (' . sprintf($queryTemplate, $expr) . ') t',
				];

				foreach ($binaryOps as $operator) {
					yield $labelPrefix . ' ' . $operator . ' 1 GROUP BY' => [
						'query' => sprintf($queryTemplate, $expr . ' ' . $operator . ' 1'),
					];
				}

				foreach ($unaryOps as $operator) {
					yield $operator . ' ' . $labelPrefix . ' GROUP BY' => [
						'query' => sprintf($queryTemplate, $operator . ' ' . $expr),
					];
				}

				yield 'NOW() - INTERVAL ' . $labelPrefix . ' GROUP BY' => [
					'query' => sprintf($queryTemplate, 'NOW() - INTERVAL ' . $expr . ' SECOND'),
				];

				yield "{$labelPrefix} BETWEEN GROUP BY" => [
					'query' => sprintf($queryTemplate, "{$expr} BETWEEN 1 AND 2"),
				];

				yield "BETWEEN {$labelPrefix} GROUP BY" => [
					'query' => sprintf($queryTemplate, "1 BETWEEN {$expr} AND 2"),
				];

				yield "(1, {$labelPrefix}) = (1, 1) GROUP BY" => [
					'query' => sprintf($queryTemplate, "(1, {$expr}) = (1, 1)"),
				];

				yield "{$labelPrefix} IN (1, 2) GROUP BY" => [
					'query' => sprintf($queryTemplate, "{$expr} IN (1, 2)"),
				];

				yield "NOW() IN (1, {$labelPrefix}) GROUP BY" => [
					'query' => sprintf($queryTemplate, "NOW() IN (1, {$expr})"),
				];

				yield "{$labelPrefix} LIKE 'a' GROUP BY" => [
					'query' => sprintf($queryTemplate, "{$expr} LIKE 'a'"),
				];

				yield "'a' LIKE {$labelPrefix} GROUP BY" => [
					'query' => sprintf($queryTemplate, "'a' LIKE {$expr}"),
				];

				yield "FN({$labelPrefix}) GROUP BY" => [
					'query' => sprintf($queryTemplate, "ROUND({$expr})"),
				];

				yield "CASE ... THEN {$labelPrefix}) GROUP BY" => [
					'query' => sprintf($queryTemplate, "CASE 1 WHEN 1 THEN {$expr} END"),
				];

				// AVG and SUM generate result in binary charset, so we can't use utf8mb4_... with them.
				$collation = in_array($fn, ['AVG', 'SUM'], true)
					? '`binary`'
					: '`utf8mb4_unicode_ci`';

				yield "{$labelPrefix} COLLATE GROUP BY" => [
					'query' => sprintf($queryTemplate, "{$expr} COLLATE {$collation}"),
				];
			}
		}

		yield "GROUP_CONCAT(not-null, 1) GROUP BY" => [
			'query' => "SELECT GROUP_CONCAT(a2.id, 1) FROM analyser_test a1, analyser_test a2 GROUP BY a1.id",
		];

		yield "GROUP_CONCAT(nullable, 1) GROUP BY" => [
			'query' => "
				SELECT GROUP_CONCAT(a3.name, 1)
				FROM (SELECT a1.* FROM analyser_test a1, analyser_test a2) t
				JOIN analyser_test a3 ON a3.id = t.id
				GROUP BY a3.id
			",
		];
	}

	/** @return iterable<string, callable(): iterable<string, array<mixed>>> set => fn(): [name => params] */
	public static function getValidNullabilityTestSets(): iterable
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query('
			CREATE OR REPLACE TABLE analyser_test_nullability_1 (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				col_vchar VARCHAR(255) NULL
			);
		');
		$db->query('
			INSERT INTO analyser_test_nullability_1 (col_vchar)
			VALUES (NULL), ("aa"), ("1"), ("2023-02-10 13:19:25"), ("18446744073709551615")
		');
		$db->query('
			CREATE OR REPLACE TABLE analyser_test_nullability_2 (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				col_int INT NULL
			);
		');
		$db->query('
			INSERT INTO analyser_test_nullability_2 (col_int)
			VALUES (NULL), (0), (1)
		');

		// To make sure that it works properly all nullable columns must return at least one NULL. If all values in the
		// column are NULL, then the analyser must infer it as NULL type.
		yield 'misc' => self::provideValidNullabilityMiscTestData(...);
		yield 'operator' => self::provideValidNullabilityOperatorTestData(...);
		yield 'group-by' => self::provideValidNullabilityGroupByTestData(...);
	}

	/** @return iterable<string, array<mixed>> */
	public static function provideValidNullabilityTestData(): iterable
	{
		foreach (self::getValidNullabilityTestSets() as $testSet) {
			yield from $testSet();
		}
	}

	/**
	 * @dataProvider provideValidNullabilityTestData
	 * @param array<scalar|null> $params
	 */
	public function testValidNullability(string $query, array $params = []): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$analyser = TestCaseHelper::createAnalyser();
		$result = $analyser->analyzeQuery($query, new VarcharPlaceholderTypeProvider());

		$this->assertSame(count($params), $result->positionalPlaceholderCount);
		$db->begin_transaction();

		try {
			$stmt = null;

			if (count($params) > 0) {
				$stmt = $db->prepare($query);
				$stmt->execute($params);
				$dbResult = $stmt->get_result();
			} else {
				$dbResult = $db->query($query);
			}

			$warningArr = $this->getRelevantWarnings($db);

			$this->assertInstanceOf(mysqli_result::class, $dbResult);
			$fields = $dbResult->fetch_fields();
			$rows = $dbResult->fetch_all(MYSQLI_NUM);
			$dbResult->close();
			$stmt?->close();
		} finally {
			$db->rollback();
		}

		if (count($warningArr) > 0) {
			$this->fail("Warnings: " . implode("\n", $warningArr));
		}

		$this->assertNotNull($result->resultFields);
		$this->assertSameSize($result->resultFields, $fields);
		$rowCount = count($rows);
		$this->assertGreaterThanOrEqual(
			1,
			$rowCount,
			'Nullability test query has to return at least 1 row, but it did not return any.',
		);
		$firstRow = $rows[0];
		$this->assertIsArray($firstRow);
		$nullCountsByColumn = array_fill_keys(array_keys($firstRow), 0);

		foreach ($rows as $row) {
			$this->assertIsArray($row);

			foreach ($row as $col => $value) {
				if ($value !== null) {
					continue;
				}

				$nullCountsByColumn[$col]++;
			}
		}

		$idx = 0;

		foreach ($result->resultFields as $field) {
			$nullCount = $nullCountsByColumn[$idx];

			if ($field->exprType->type::getTypeEnum() === DbTypeEnum::NULL) {
				$this->assertSame(
					$rowCount,
					$nullCount,
					$this->getFieldNameForErrorMessage($field) . ' was detected as NULL, but there is non-NULL value.',
				);
			} elseif ($field->exprType->isNullable) {
				$this->assertGreaterThanOrEqual(
					1,
					$nullCount,
					"There are no NULLs in " . $this->getFieldNameForErrorMessage($field)
						. " but the analyser thinks it is nullable.",
				);
				$this->assertLessThan(
					$rowCount,
					$nullCount,
					'All the values in ' . $this->getFieldNameForErrorMessage($field)
						. ' are NULL. The analyser should infer NULL type.',
				);
			} else {
				$this->assertSame(
					0,
					$nullCount,
					'There are NULLs in ' . $this->getFieldNameForErrorMessage($field)
						. ', but it is supposed to be non-nullable.',
				);
			}

			$idx++;
		}
	}

	/** @return iterable<string, callable(): iterable<string, array<mixed>>> set => fn(): [name => params] */
	public static function getInvalidTestSets(): iterable
	{
		yield 'misc' => static function (): iterable {
			// TODO: improve the error messages to match MariaDB errors more closely.
			yield 'usage of previous alias in field list' => [
				'query' => 'SELECT 1+1 aaa, aaa + 1 FROM analyser_test',
				'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield 'subquery - forward reference to alias in field list' => [
				'query' => 'SELECT (SELECT aaa), 1 aaa',
				'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
				'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_REFERENCE,
			];

			yield 'subquery - reference field alias in WHERE' => [
				'query' => 'SELECT 1 aaa WHERE (SELECT aaa) = 1',
				'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield 'not unique table name in top-level query' => [
				'query' => 'SELECT * FROM analyser_test, analyser_test',
				'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('analyser_test'),
				'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
			];

			yield 'not unique table alias in top-level query' => [
				'query' => 'SELECT * FROM analyser_test t, analyser_test t',
				'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('t'),
				'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
			];

			yield 'not unique subquery alias' => [
				'query' => 'SELECT * FROM (SELECT 1) t, (SELECT 1) t',
				'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('t'),
				'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
			];

			yield 'not unique table alias - nested JOIN' => [
				'query' => 'SELECT * FROM (analyser_test a, analyser_test b) JOIN (analyser_test a, analyser_test c)',
				'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('a'),
				'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
			];

			yield 'not unique table - nested JOIN' => [
				'query' => 'SELECT * FROM (analyser_test, (SELECT 1) b) JOIN (analyser_test, (SELECT 2) c)',
				'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('analyser_test'),
				'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
			];

			yield 'not unique table in subquery' => [
				'query' => 'SELECT * FROM (SELECT 1 FROM analyser_test, analyser_test) t',
				'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('analyser_test'),
				'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
			];

			yield 'not unique table alias in WITH' => [
				'query' => 'WITH tbl AS (SELECT 1), tbl AS (SELECT 1) SELECT * FROM tbl',
				'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('tbl'),
				'DB error code' => MariaDbErrorCodes::ER_DUP_QUERY_NAME,
			];

			yield 'duplicate column name in subquery' => [
				'query' => 'SELECT * FROM (SELECT * FROM analyser_test a, analyser_test b) t',
				'error' => (new DuplicateFieldNameException('id'))->toAnalyserError(),
				'DB error code' => MariaDbErrorCodes::ER_DUP_FIELDNAME,
			];

			yield 'duplicate column name in WITH' => [
				'query' => 'WITH analyser_test AS (SELECT 1, 1) SELECT * FROM analyser_test',
				'error' => (new DuplicateFieldNameException('1'))->toAnalyserError(),
				'DB error code' => MariaDbErrorCodes::ER_DUP_FIELDNAME,
			];

			yield 'duplicate column name in WITH - explicit column list' => [
				'query' => 'WITH tbl (id, id) AS (SELECT 1, 2) SELECT 1',
				'error' => (new DuplicateFieldNameException('id'))->toAnalyserError(),
				'DB error code' => MariaDbErrorCodes::ER_DUP_FIELDNAME,
			];

			yield 'WITH - mismatched number of columns in column list' => [
				'query' => 'WITH tbl (id, aa) AS (SELECT 1) SELECT * FROM tbl',
				'error' => AnalyserErrorBuilder::createDifferentNumberOfWithColumnsError(2, 1),
				'DB error code' => MariaDbErrorCodes::ER_WITH_COL_WRONG_LIST,
			];

			yield "LIKE - multichar ESCAPE literal" => [
				'query' => "SELECT 'a' LIKE 'b' ESCAPE 'cd'",
				'error' => AnalyserErrorBuilder::createInvalidLikeEscapeMulticharError('cd'),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_ARGUMENTS,
			];

			yield 'bug - valid subquery should not clear errors from parent query' => [
				'query' => 'SELECT v.id, (SELECT id FROM analyser_test LIMIT 1) aa FROM analyser_test',
				'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];
		};

		yield 'column' => self::provideInvalidColumnTestData(...);
		yield 'tuple' => self::provideInvalidTupleTestData(...);
		yield 'union' => self::provideInvalidUnionTestData(...);
		yield 'insert' => self::provideInvalidInsertTestData(...);
		yield 'other-query' => self::provideInvalidOtherQueryTestData(...);
		yield 'update' => self::provideInvalidUpdateTestData(...);
		yield 'delete' => self::provideInvalidDeleteTestData(...);
		yield 'table-value-constructor' => self::provideInvalidTableValueConstructorData(...);
		yield 'unsupported-feature' => self::provideInvalidUnsupportedFeaturesData(...);
	}

	/** @return iterable<string, array<mixed>> */
	public static function provideInvalidTestData(): iterable
	{
		foreach (self::getInvalidTestSets() as $testSet) {
			yield from $testSet();
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidColumnTestData(): iterable
	{
		yield 'unknown column in field list' => [
			'query' => 'SELECT v.id FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in field list' => [
			'query' => 'SELECT (SELECT v.id FROM analyser_test)',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in FROM' => [
			'query' => 'SELECT * FROM (SELECT v.id FROM analyser_test) t JOIN analyser_test v',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in EXISTS' => [
			'query' => 'SELECT EXISTS (SELECT v.id FROM analyser_test)',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - IS' => [
			'query' => 'SELECT v.id IS NULL FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - LIKE - left' => [
			'query' => 'SELECT v.id LIKE "a" FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - LIKE - right' => [
			'query' => 'SELECT "a" LIKE v.id FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - WITH' => [
			'query' => 'WITH tbl AS (SELECT aaa FROM analyser_test) SELECT * FROM tbl',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'ambiguous column in field list' => [
			'query' => 'SELECT id FROM analyser_test a, analyser_test b',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'unknown column in WHERE' => [
			'query' => 'SELECT * FROM analyser_test WHERE v.id',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'using field list alias in WHERE' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test WHERE aaa',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in GROUP BY' => [
			'query' => 'SELECT * FROM analyser_test GROUP BY v.id',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'ambiguous column in GROUP BY' => [
			'query' => 'SELECT a.id, b.id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in GROUP BY - explicit alias' => [
			'query' => 'SELECT a.id, b.name id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in GROUP BY - *, expression alias' => [
			'query' => 'SELECT *, 1+1 id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in GROUP BY - a.id, b.id, 1+1 id' => [
			'query' => 'SELECT a.id, b.id, 1+1 id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		// table wins, ambiguous
		yield 'Warning: ambiguous column in GROUP BY - same alias in field list vs 1 table and *' => [
			'query' => 'SELECT *, 1+1 id FROM analyser_test GROUP BY id',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id', null, true),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		// table wins, ambiguous
		yield 'Warning: ambiguous column in GROUP BY - same alias in fields list vs 1 table' => [
			'query' => 'SELECT 1 id FROM analyser_test t1 GROUP BY id',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id', null, true),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		// table wins, ambiguous
		yield 'Warning: ambiguous column in GROUP BY - same alias in fields list (another column) vs 1 table' => [
			// col_enum happens to have the same value everywhere so it showcases how it works.
			'query' => 'SELECT col_enum col_int FROM analyser_test_data_types t1 GROUP BY col_int',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('col_int', null, true),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'Warning: ambiguous column in GROUP BY - same alias in fields list vs 1 table - in function call' => [
			'query' => 'SELECT col_enum col_int FROM analyser_test_data_types t1 GROUP BY ROUND(col_int)',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('col_int', null, true),
			// MariaDB 10.6 doesn't report a warning here, but IMO it is still ambiguous.
			'DB error code' => null,
		];

		yield 'unknown column in HAVING' => [
			'query' => 'SELECT * FROM analyser_test HAVING v.id',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column as function argument' => [
			'query' => 'SELECT AVG(v.id) FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in HAVING - not in field list nor in GROUP BY nor aggregate' => [
			'query' => 'SELECT 1 FROM analyser_test GROUP BY id HAVING name',
			'error' => AnalyserErrorBuilder::createInvalidHavingColumnError('name'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in HAVING - aggregate in subquery, followed by unaggregated use' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test HAVING (SELECT COUNT(name)) AND name',
			'error' => AnalyserErrorBuilder::createInvalidHavingColumnError('name'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in HAVING - aggregate in SELECT, unaggregated use in HAVING' => [
			'query' => 'SELECT COUNT(name) FROM analyser_test HAVING name',
			'error' => AnalyserErrorBuilder::createInvalidHavingColumnError('name'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in HAVING - non-aggregate function' => [
			'query' => 'SELECT id FROM analyser_test HAVING CAST(name AS INT)',
			'error' => AnalyserErrorBuilder::createInvalidHavingColumnError('name'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in ORDER BY' => [
			'query' => 'SELECT * FROM analyser_test ORDER BY v.id',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in INTERVAL' => [
			'query' => 'SELECT "2022-08-27" - INTERVAL v.id DAY FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown table in JOIN' => [
			'query' => 'SELECT * FROM analyser_test JOIN aaabbbccc',
			'error' => AnalyserErrorBuilder::createTableDoesntExistError('aaabbbccc'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield 'subquery - cannot use alias from outer query in FROM' => [
			'query' => 'SELECT (SELECT id FROM t) FROM analyser_test t',
			'error' => AnalyserErrorBuilder::createTableDoesntExistError('t'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield 'unknown column in JOIN ... ON' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b ON a.id = b.aaa',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa', 'b'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... ON - referring to table joined later' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b ON b.id = c.id JOIN analyser_test c',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'c'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... ON - JOIN has higher precedence than comma' => [
			'query' => 'SELECT * FROM analyser_test a, analyser_test b JOIN analyser_test c ON a.id = c.id ',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... USING - JOIN has higher precedence than comma' => [
			'query' => 'SELECT * FROM (SELECT 1 aa) a, (SELECT 2 bb) b JOIN (SELECT 1 aa, 2 bb) c USING (aa)',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... USING - column exists only on one side - right' => [
			'query' => 'SELECT * FROM (SELECT 1 aa) a JOIN (SELECT 2 bb) b using (bb)',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('bb'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... USING - column exists only on one side - left' => [
			'query' => 'SELECT * FROM (SELECT 1 aa) a JOIN (SELECT 2 bb) b using (aa)',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'ambiguous column in JOIN ... USING' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b JOIN analyser_test c USING (id)',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in JOIN ... USING - multiple' => [
			'query' => "
				SELECT * FROM
				(SELECT 3 cc, 2 bb, 5 ee) tm
				JOIN
				(
				    ((SELECT 1 aa) t1, (SELECT 2 bb) t2, (SELECT 3 cc) t3, (SELECT 4 dd) t4)
					JOIN
					((SELECT 2 bb) t5, (SELECT 4 dd) t6, (SELECT 1 aa) t7, (SELECT 3 cc) t8)
					USING (bb, aa)
				) USING (cc)
			",
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('cc'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in JOIN ... ON' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b ON a.id = id',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column as function argument' => [
			'query' => 'SELECT AVG(id) FROM analyser_test a, analyser_test b',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'unknown column in tuple' => [
			'query' => 'SELECT (id, name) = (id, aaa) FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE' => [
			'query' => 'SELECT CASE aaa WHEN 1 THEN 1 END FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE WHEN' => [
			'query' => 'SELECT CASE 1 WHEN aaa THEN 1 END FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE THEN' => [
			'query' => 'SELECT CASE 1 WHEN 1 THEN aaa END FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE ELSE' => [
			'query' => 'SELECT CASE 1 WHEN 1 THEN 1 ELSE aaa END FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in COLLATE' => [
			'query' => 'SELECT aaa COLLATE utf8mb4_unicode_ci FROM analyser_test',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'cannot use table alias from two levels up through FROM' => [
			'query' => 'SELECT t.*, (SELECT * FROM (SELECT t.a) t2) FROM (SELECT 1 a) t',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('a', 't'),
			'DB error code' => MariaDbErrorCodes::ER_UNKNOWN_TABLE,
		];

		yield 'cannot use column from two levels up through FROM' => [
			'query' => 'SELECT t.*, (SELECT * FROM (SELECT a) t2) FROM (SELECT 1 a) t',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'cannot use column from two levels up through FROM - WHERE' => [
			'query' => 'SELECT t.*, (SELECT * FROM (SELECT 1 WHERE a) t2) FROM (SELECT 1 a) t',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'cannot use column from two levels up through FROM - GROUP BY' => [
			'query' => 'SELECT t.*, (SELECT * FROM (SELECT 1 GROUP BY a) t2) FROM (SELECT 1 a) t',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'cannot use column from two levels up through FROM - HAVING' => [
			'query' => 'SELECT t.*, (SELECT * FROM (SELECT 1 HAVING a) t2) FROM (SELECT 1 a) t',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'cannot use column from two levels up through FROM - ORDER BY' => [
			'query' => 'SELECT t.*, (SELECT * FROM (SELECT 1 ORDER BY a) t2) FROM (SELECT 1 a) t',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'cannot use field alias from two levels up through FROM' => [
			'query' => 'SELECT 1 a, (SELECT * FROM (SELECT a) t);',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidTupleTestData(): iterable
	{
		yield 'tuple size does not match' => [
			'query' => 'SELECT (id, name, 1) = (id, name) FROM analyser_test',
			'error' => AnalyserErrorBuilder::createInvalidTupleComparisonError(
				self::createMockTuple(3),
				self::createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield 'tuple: single value vs multi-column SELECT' => [
			'query' => 'SELECT 1 IN (SELECT 1, 2)',
			'error' => AnalyserErrorBuilder::createInvalidTupleComparisonError(
				new IntType(),
				self::createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield 'tuple: flat tuple both on left and right with IN' => [
			'query' => 'SELECT (1, 2) IN (1, 2)',
			'error' => AnalyserErrorBuilder::createInvalidTupleComparisonError(
				self::createMockTuple(2),
				new IntType(),
			),
			'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION,
		];

		yield 'tuple: (tuple) IN (SELECT ..., SELECT ...)' => [
			'query' =>
				'SELECT (1,2) IN ((SELECT id FROM analyser_test LIMIT 1), (SELECT id FROM analyser_test LIMIT 1))',
			'error' => AnalyserErrorBuilder::createInvalidTupleComparisonError(
				self::createMockTuple(2),
				new IntType(),
			),
			'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION,
		];

		yield 'tuple: nested tuples with IN and missing parentheses on right' => [
			// This works if right side is wrapped in one more parentheses
			'query' => 'SELECT ((1,2), 3) IN ((1,2), 3)',
			'error' => AnalyserErrorBuilder::createInvalidTupleComparisonError(
				self::createMockTuple(2),
				new IntType(),
			),
			'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION,
		];

		$invalidOperators = [
			MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION => [
				'+',
				'-',
				'*',
				'/',
				'%',
			],
			MariaDbErrorCodes::ER_OPERAND_COLUMNS => [
				'DIV',
				'AND',
				'OR',
				'XOR',
			],
			MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPE_FOR_OPERATION => [
				'<<',
				'>>',
				'&',
				'|',
			],
		];

		foreach ($invalidOperators as $mariadbErrorCode => $operators) {
			foreach ($operators as $invalidOperator) {
				yield "invalid operator with tuples - {$invalidOperator}" => [
					'query' => "SELECT (id, name, 1) {$invalidOperator} (1, 'aa') FROM analyser_test",
					'error' => AnalyserErrorBuilder::createInvalidBinaryOpUsageError(
						BinaryOpTypeEnum::from($invalidOperator),
						DbTypeEnum::TUPLE,
						DbTypeEnum::TUPLE,
					),
					'DB error code' => $mariadbErrorCode,
				];

				yield "invalid operator with tuples - tuple {$invalidOperator} 1" => [
					'query' => "SELECT (id, name, 1) {$invalidOperator} 1 FROM analyser_test",
					'error' => AnalyserErrorBuilder::createInvalidBinaryOpUsageError(
						BinaryOpTypeEnum::from($invalidOperator),
						DbTypeEnum::TUPLE,
						DbTypeEnum::INT,
					),
					'DB error code' => $mariadbErrorCode,
				];
			}
		}

		yield "invalid operator with tuples - CASE WHEN" => [
			'query' => "SELECT CASE WHEN (1,1) THEN 1 END",
			'error' => AnalyserErrorBuilder::createInvalidTupleUsageError(
				self::createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - CASE tuple WHEN tuple" => [
			'query' => "SELECT CASE (1, 1) WHEN (1,1) THEN 1 END",
			'error' => [
				AnalyserErrorBuilder::createInvalidTupleUsageError(
					self::createMockTuple(2),
				),
				AnalyserErrorBuilder::createInvalidTupleUsageError(
					self::createMockTuple(2),
				),
			],
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - CASE THEN" => [
			'query' => "SELECT CASE WHEN 1 THEN (1,1) END",
			'error' => AnalyserErrorBuilder::createInvalidTupleUsageError(
				self::createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - CASE ELSE" => [
			'query' => "SELECT CASE WHEN 1 THEN 0 ELSE (1,1) END",
			'error' => AnalyserErrorBuilder::createInvalidTupleUsageError(
				self::createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - LIKE" => [
			'query' => "SELECT (id, name, 1) LIKE (1, 'aa') FROM analyser_test",
			'error' => AnalyserErrorBuilder::createInvalidLikeUsageError(DbTypeEnum::TUPLE, DbTypeEnum::TUPLE),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - LIKE - tuple in escape char" => [
			'query' => "SELECT name LIKE 'a' ESCAPE (1, 2) FROM analyser_test",
			'error' => AnalyserErrorBuilder::createInvalidLikeUsageError(
				DbTypeEnum::VARCHAR,
				DbTypeEnum::VARCHAR,
				DbTypeEnum::TUPLE,
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - tuple LIKE 1" => [
			'query' => "SELECT (id, name, 1) LIKE 1 FROM analyser_test",
			'error' => AnalyserErrorBuilder::createInvalidLikeUsageError(DbTypeEnum::TUPLE, DbTypeEnum::INT),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield 'tuple as function argument' => [
			'query' => 'SELECT AVG((id, name)) FROM analyser_test',
			'error' => AnalyserErrorBuilder::createInvalidFunctionArgumentError(
				'AVG',
				1,
				self::createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidUnionTestData(): iterable
	{
		foreach (SelectQueryCombinatorTypeEnum::cases() as $combinator) {
			$combinatorVal = $combinator->value;

			yield "{$combinatorVal} - error in left query" => [
				'query' => "SELECT v.id FROM analyser_test {$combinatorVal} SELECT id FROM analyser_test",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - error in right query" => [
				'query' => "SELECT id FROM analyser_test {$combinatorVal} SELECT v.id FROM analyser_test",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - error in nested query" => [
				'query' => "
					SELECT id FROM analyser_test
					{$combinatorVal}
					SELECT v.id FROM analyser_test
					{$combinatorVal} SELECT 1
				",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'v'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - error when used as subquery in FROM" => [
				'query' => "SELECT * FROM (SELECT 1 {$combinatorVal} SELECT 2, 3) t",
				'error' => AnalyserErrorBuilder::createDifferentNumberOfColumnsError(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_NUMBER_OF_COLUMNS_IN_SELECT,
			];

			yield "{$combinatorVal} - error when used as subquery expression" => [
				'query' => "SELECT 1 IN (SELECT 1 {$combinatorVal} SELECT 2, 3)",
				'error' => AnalyserErrorBuilder::createDifferentNumberOfColumnsError(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
			];

			yield "{$combinatorVal} - cannot use column name from second query in ORDER BY" => [
				'query' => "SELECT 1 aa {$combinatorVal} SELECT 2 bb ORDER BY bb",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('bb'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - cannot use original name of aliased column in ORDER BY" => [
				'query' => "SELECT id aa FROM analyser_test {$combinatorVal} SELECT 2 bb ORDER BY id",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('id'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - cannot use table.column ORDER BY" => [
				'query' => "
					SELECT id FROM analyser_test
					{$combinatorVal}
					SELECT id FROM analyser_test
					ORDER BY analyser_test.id
				",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('id', 'analyser_test'),
				'DB error code' => MariaDbErrorCodes::ER_TABLENAME_NOT_ALLOWED_HERE,
			];

			yield "{$combinatorVal} - different number of columns" => [
				'query' => "SELECT 1 {$combinatorVal} SELECT 2, 3",
				'error' => AnalyserErrorBuilder::createDifferentNumberOfColumnsError(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_NUMBER_OF_COLUMNS_IN_SELECT,
			];

			yield "{$combinatorVal} - cannot use columns from first query in second query" => [
				'query' => "SELECT id aa FROM analyser_test {$combinatorVal} SELECT 2 + aa",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('aa'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidOtherQueryTestData(): iterable
	{
		yield "TRUNCATE missing_table" => [
			'query' => "TRUNCATE missing_table",
			'error' => [
				AnalyserErrorBuilder::createTableDoesntExistError('missing_table'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidInsertTestData(): iterable
	{
		foreach (['INSERT', 'REPLACE'] as $type) {
			yield "{$type} INTO missing_table" => [
				'query' => "{$type} INTO missing_table SET col = 'value'",
				'error' => [
					AnalyserErrorBuilder::createTableDoesntExistError('missing_table'),
					AnalyserErrorBuilder::createUnknownColumnError('col'),
				],
				'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
			];

			yield "{$type} INTO ... SET missing_column" => [
				'query' => "{$type} INTO analyser_test SET missing_column = 'value'",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('missing_column'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$type} INTO ... (missing_column) VALUES ..." => [
				'query' => "{$type} INTO analyser_test (id, name, missing_column) VALUES (999, 'adasd', 1)",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('missing_column'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$type} INTO ... (missing_column) SELECT ..." => [
				'query' => "{$type} INTO analyser_test (id, name, missing_column) SELECT 999, 'adasd', 1",
				'error' => AnalyserErrorBuilder::createUnknownColumnError('missing_column'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$type} INTO ... SELECT - issue in SELECT" => [
				'query' => "{$type} INTO analyser_test SELECT * FROM missing_table",
				'error' => AnalyserErrorBuilder::createTableDoesntExistError('missing_table'),
				'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
			];

			yield "{$type} INTO ... VALUES - issue in tuple" => [
				'query' => "{$type} INTO analyser_test (name) VALUES (1 = (1, 2))",
				'error' => AnalyserErrorBuilder::createInvalidTupleComparisonError(
					new IntType(),
					self::createMockTuple(2),
				),
				'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999, 'adasd', 1)",
				'error' => AnalyserErrorBuilder::createMismatchedInsertColumnCountError(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count - in some tuples" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999, 'adasd'), (998, 'aaa', 1)",
				'error' => AnalyserErrorBuilder::createMismatchedInsertColumnCountError(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count - in multiple tuples" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999), (998, 'aaa', 1)",
				'error' => AnalyserErrorBuilder::createMismatchedInsertColumnCountError(2, 1),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count + error in tuple" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999, 'adasd'), (998, 'aaa', 1),"
					. " (111, 1 = (1, 1))",
				'error' => [
					AnalyserErrorBuilder::createInvalidTupleComparisonError(
						new IntType(),
						self::createMockTuple(2),
					),
					AnalyserErrorBuilder::createMismatchedInsertColumnCountError(2, 3),
				],
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count - implicit column list" => [
				'query' => "{$type} INTO analyser_test VALUES (999, 'adasd', 1)",
				'error' => AnalyserErrorBuilder::createMismatchedInsertColumnCountError(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) SELECT ... - mismatched column count" => [
				'query' => "{$type} INTO analyser_test (name) SELECT 'adasd', 1",
				'error' => AnalyserErrorBuilder::createMismatchedInsertColumnCountError(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) SELECT ... - mismatched column count - implicit column list" => [
				'query' => "{$type} INTO analyser_test SELECT 999, 'adasd', 1",
				'error' => AnalyserErrorBuilder::createMismatchedInsertColumnCountError(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} ... VALUES - skip column without default value" => [
				'query' => "{$type} INTO analyse_test_insert (val_string_null_default) VALUES ('aaa')",
				'error' => AnalyserErrorBuilder::createMissingValueForColumnError('val_string_not_null_no_default'),
				'DB error code' => MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD,
			];

			yield "{$type} ... SET - skip column without default value" => [
				'query' => "{$type} INTO analyse_test_insert SET val_string_null_default = 'aaa'",
				'error' => AnalyserErrorBuilder::createMissingValueForColumnError('val_string_not_null_no_default'),
				'DB error code' => MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD,
			];

			yield "{$type} ... SELECT - skip column without default value" => [
				'query' => "{$type} INTO analyse_test_insert (val_string_null_default) SELECT 'aaa'",
				'error' => AnalyserErrorBuilder::createMissingValueForColumnError('val_string_not_null_no_default'),
				'DB error code' => MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD,
			];
		}

		yield "INSERT INTO ... ON DUPLICATE KEY UPDATE missing_column" => [
			'query' => "
				INSERT INTO analyser_test (id, name) SELECT 999, 'adasd'
				ON DUPLICATE KEY UPDATE missing_column = 1
			",
			'error' => AnalyserErrorBuilder::createUnknownColumnError('missing_column'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - reference column from SELECT - ambiguous column' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default)
				SELECT id, "abcd" FROM analyser_test
				ON DUPLICATE KEY UPDATE analyse_test_insert.id = id
			',
			'error' => AnalyserErrorBuilder::createAmbiguousColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - writing into the SELECT table' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default)
				SELECT id, "abcd" FROM analyser_test
				ON DUPLICATE KEY UPDATE analyser_test.id = 1
			',
			'error' => AnalyserErrorBuilder::createAssignToReadonlyColumnError('id', 'analyser_test'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'INSERT ... ON DUPLICATE KEY UPDATE - writing into the SELECT subqeuery' => [
			'query' => '
				INSERT INTO analyse_test_insert (id, val_string_not_null_no_default)
				SELECT * FROM (SELECT 1 id, "abcd" name) t
				ON DUPLICATE KEY UPDATE t.id = 1
			',
			'error' => AnalyserErrorBuilder::createAssignToReadonlyColumnError('id', 't'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidUpdateTestData(): iterable
	{
		yield "UPDATE missing_table" => [
			'query' => "UPDATE missing_table SET col = 'value'",
			'error' => [
				AnalyserErrorBuilder::createTableDoesntExistError('missing_table'),
				AnalyserErrorBuilder::createUnknownColumnError('col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield "UPDATE ... SET missing_col = ..." => [
			'query' => "UPDATE analyser_test SET missing_col = 1",
			'error' => [
				AnalyserErrorBuilder::createUnknownColumnError('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield "UPDATE ... SET ambiguous_col" => [
			'query' => "UPDATE analyser_test t1, analyser_test t2 SET name = 'aa'",
			'error' => [
				AnalyserErrorBuilder::createAmbiguousColumnError('name'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield "UPDATE - not unique table/alias" => [
			'query' => "UPDATE analyser_test, analyser_test SET name = 'aa'",
			'error' => [
				AnalyserErrorBuilder::createNotUniqueTableAliasError('analyser_test'),
				AnalyserErrorBuilder::createUnknownColumnError('name'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield "UPDATE - error in table reference subquery" => [
			'query' => "UPDATE analyser_test t1, (SELECT * FROM missing_table) t2 SET t1.name = 'aa'",
			'error' => [
				AnalyserErrorBuilder::createTableDoesntExistError('missing_table'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield "UPDATE - error in SET expression" => [
			'query' => "UPDATE analyser_test SET name = missing_col + 5",
			'error' => [
				AnalyserErrorBuilder::createUnknownColumnError('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield "UPDATE - error in WHERE expression" => [
			'query' => "UPDATE analyser_test SET name = 'aa' WHERE missing_col > 5",
			'error' => [
				AnalyserErrorBuilder::createUnknownColumnError('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield "UPDATE - error in ORDER BY expression" => [
			'query' => "UPDATE analyser_test SET name = 'aa' ORDER BY missing_col > 5",
			'error' => [
				AnalyserErrorBuilder::createUnknownColumnError('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield "UPDATE - trying to update subquery" => [
			'query' => "UPDATE (SELECT * FROM analyser_test) t SET name = 'aa'",
			'error' => [
				AnalyserErrorBuilder::createAssignToReadonlyColumnError('name'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NON_UPDATABLE_TABLE,
		];

		yield "UPDATE - trying to update subquery - with normal table as well" => [
			'query' => "UPDATE (SELECT 1 aaa) t, analyser_test SET name = 'aaa', aaa = 2",
			'error' => [
				AnalyserErrorBuilder::createAssignToReadonlyColumnError('aaa'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NON_UPDATABLE_TABLE,
		];

		yield "UPDATE - trying to update subquery - with normal table as well - ambiguous" => [
			'query' => "UPDATE (SELECT 1 name) t, analyser_test SET name = 'aaa'",
			'error' => [
				AnalyserErrorBuilder::createAmbiguousColumnError('name'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidDeleteTestData(): iterable
	{
		yield 'DELETE - missing table' => [
			'query' => 'DELETE FROM missing_table',
			'error' => AnalyserErrorBuilder::createTableDoesntExistError('missing_table'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield 'DELETE - wrong alias' => [
			'query' => 'DELETE t_miss FROM analyser_test_truncate t1',
			'error' => AnalyserErrorBuilder::createTableDoesntExistError('t_miss'),
			'DB error code' => MariaDbErrorCodes::ER_UNKNOWN_TABLE,
		];

		yield 'DELETE - by table name despite alias' => [
			'query' => 'DELETE analyser_test_truncate FROM analyser_test_truncate t1',
			'error' => AnalyserErrorBuilder::createTableDoesntExistError('analyser_test_truncate'),
			'DB error code' => MariaDbErrorCodes::ER_UNKNOWN_TABLE,
		];

		yield 'DELETE - error in WHERE' => [
			'query' => 'DELETE FROM analyser_test_truncate WHERE missing_col > 1',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('missing_col'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'DELETE - error in ORDER BY' => [
			'query' => 'DELETE FROM analyser_test_truncate ORDER BY missing_col',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('missing_col'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'DELETE - error in subquery' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1, (SELECT * FROM missing_table) t2',
			'error' => AnalyserErrorBuilder::createTableDoesntExistError('missing_table'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		// having the same alias for table and subquery works in SELECT
		yield 'DELETE - duplicate alias - subquery - alias' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1, (SELECT 1) t1',
			'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('t1'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'DELETE - duplicate alias - subquery - table name' => [
			'query' => 'DELETE analyser_test_truncate FROM analyser_test_truncate, (SELECT 1) analyser_test_truncate',
			'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('analyser_test_truncate'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'DELETE - duplicate alias - table' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1, analyser_test_truncate t1',
			'error' => AnalyserErrorBuilder::createNotUniqueTableAliasError('t1'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		// TODO: detect deleting non-tables
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidTableValueConstructorData(): iterable
	{
		yield 'TVC - different number of values' => [
			'query' => 'WITH t AS (VALUES (1, 2), (3)) SELECT * FROM t',
			'error' => AnalyserErrorBuilder::createTvcDifferentNumberOfValues(1, 2),
			'DB error code' => MariaDbErrorCodes::ER_WRONG_NUMBER_OF_VALUES_IN_TVC,
		];

		yield 'TVC - unknown column' => [
			'query' => 'WITH t AS (VALUES (1, id)) SELECT * FROM t',
			'error' => AnalyserErrorBuilder::createUnknownColumnError('id'),
			'DB error code' => MariaDbErrorCodes::ER_FIELD_REFERENCE_IN_TVC,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private static function provideInvalidUnsupportedFeaturesData(): iterable
	{
		yield 'LIMIT inside of IN - simple' => [
			'query' => 'SELECT 1 IN (SELECT id FROM analyser_test LIMIT 10)',
			'error' => AnalyserErrorBuilder::createNoLimitInsideIn(),
			'DB error code' => MariaDbErrorCodes::ER_NOT_SUPPORTED_YET,
		];

		yield 'LIMIT inside of IN - WITH ... SIMPLE' => [
			'query' => 'SELECT 1 IN (WITH t AS (SELECT * FROM analyser_test) SELECT id FROM t LIMIT 10)',
			'error' => AnalyserErrorBuilder::createNoLimitInsideIn(),
			'DB error code' => MariaDbErrorCodes::ER_NOT_SUPPORTED_YET,
		];

		yield 'LIMIT inside of IN - WITH ... UNION' => [
			'query' => 'SELECT 1 IN (
				WITH t AS (SELECT * FROM analyser_test) SELECT id FROM t UNION ALL SELECT id FROM t LIMIT 10
			)',
			'error' => AnalyserErrorBuilder::createNoLimitInsideIn(),
			'DB error code' => MariaDbErrorCodes::ER_NOT_SUPPORTED_YET,
		];

		yield 'LIMIT inside of IN - UNION top level LIMIT' => [
			'query' => 'SELECT 1 IN (SELECT id FROM analyser_test UNION ALL SELECT id FROM analyser_test LIMIT 50)',
			'error' => AnalyserErrorBuilder::createNoLimitInsideIn(),
			'DB error code' => MariaDbErrorCodes::ER_NOT_SUPPORTED_YET,
		];

		// TODO: fix this. Currently we're unable to parse the query.
		//yield 'LIMIT inside of IN - UNION subquery LIMIT' => [
		//	'query' => 'SELECT 1 IN ((SELECT id FROM analyser_test LIMIT 50) UNION ALL SELECT id FROM analyser_test)',
		//	'error' => AnalyserErrorBuilder::createNoLimitInsideIn(),
		//	'DB error code' => MariaDbErrorCodes::ER_NOT_SUPPORTED_YET,
		//];
	}

	/**
	 * @param AnalyserError|array<AnalyserError> $error
	 * @dataProvider provideInvalidTestData
	 */
	public function testInvalid(string $query, AnalyserError|array $error, ?int $dbErrorCode): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$analyser = TestCaseHelper::createAnalyser();
		$result = $analyser->analyzeQuery($query);

		if (! is_array($error)) {
			$error = [$error];
		}

		$this->assertEquals($error, $result->errors);
		$db->begin_transaction();

		try {
			$db->query($query);
			$warning = $db->get_warnings();

			if ($dbErrorCode === null) {
				$this->assertFalse($warning);

				return;
			}

			if ($warning === false) {
				$this->fail('Expected mysqli_sql_exception.');
			}

			$this->assertSame($dbErrorCode, $warning->errno);
		} catch (mysqli_sql_exception $e) {
			$this->assertNotNull($dbErrorCode, 'No DB error was expected');
			$this->assertSame($dbErrorCode, $e->getCode());
		} finally {
			$db->rollback();
		}
	}

	/** @return iterable<string, array<mixed>> */
	public static function provideTestPositionPlaceholderCountData(): iterable
	{
		yield 'no placeholders' => [
			'query' => 'SELECT 1',
			'expected count' => 0,
		];

		yield 'no placeholders - ? as string' => [
			'query' => 'SELECT "?"',
			'expected count' => 0,
		];

		yield 'placeholder in field list' => [
			'query' => 'SELECT ?',
			'expected count' => 1,
		];

		yield 'placeholder in subquery' => [
			'query' => 'SELECT 0 FROM (SELECT ?, ? + ?) a',
			'expected count' => 3,
		];

		yield 'placeholder in ON' => [
			'query' => 'SELECT 0 FROM (SELECT 1) a JOIN (SELECT 2) b ON ?',
			'expected count' => 1,
		];

		yield 'placeholder in WHERE' => [
			'query' => 'SELECT 0 WHERE ?',
			'expected count' => 1,
		];

		yield 'placeholder in GROUP BY' => [
			'query' => 'SELECT 0 GROUP BY ?',
			'expected count' => 1,
		];

		yield 'placeholder in HAVING' => [
			'query' => 'SELECT 0 HAVING ?',
			'expected count' => 1,
		];

		yield 'placeholder in ORDER BY' => [
			'query' => 'SELECT 0 ORDER BY ?',
			'expected count' => 1,
		];

		yield 'placeholder in LIMIT' => [
			'query' => 'SELECT 0 LIMIT ?, ?',
			'expected count' => 2,
		];
	}

	/** @dataProvider provideTestPositionPlaceholderCountData */
	public function testPositionalPlaceholderCount(string $query, int $expectedCount): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$analyser = TestCaseHelper::createAnalyser();
		$result = $analyser->analyzeQuery($query);
		$this->assertCount(
			0,
			$result->errors,
			"Expected 0 errors. Got: "
			. implode("\n", array_map(static fn (AnalyserError $e) => $e->message, $result->errors)),
		);

		if ($expectedCount === 0) {
			$db->query($query);
		} else {
			$stmt = $db->prepare($query);
			$values = array_fill(0, $expectedCount, '1');
			$stmt->execute($values);
			$stmt->close();
		}

		$this->assertSame($expectedCount, $result->positionalPlaceholderCount);
	}

	/** @return iterable<string, array<mixed>> */
	public static function provideTestRowCountRangeData(): iterable
	{
		$singleRowQueries = [
			'SELECT 1',
			'SELECT 1 LIMIT 2',
			'SELECT COUNT(*)',
			'SELECT (SELECT COUNT(*))',
			'SELECT COUNT(*) FROM analyser_test WHERE id > 5',
			'SELECT 1 FROM analyser_test ORDER BY COUNT(*)',
			'SELECT 1 FROM analyser_test WHERE id > 5 ORDER BY COUNT(*)',
		];

		foreach ($singleRowQueries as $query) {
			yield $query => [
				'query' => $query,
				'expected range' => QueryResultRowCountRange::createFixed(1),
			];
		}

		$uncertainSingleRowQueries = [
			'SELECT id FROM analyser_test WHERE id > 5 HAVING COUNT(*)',
			'SELECT id FROM analyser_test HAVING id > 5 ORDER BY COUNT(*)',
		];

		foreach ($uncertainSingleRowQueries as $query) {
			yield $query => [
				'query' => $query,
				'expected range' => new QueryResultRowCountRange(0, 1),
			];
		}

		yield 'no FROM LIMIT 1, 2' => [
			'query' => 'SELECT 1 LIMIT 1, 2',
			'expected range' => QueryResultRowCountRange::createFixed(0),
		];

		yield 'no FROM LIMIT 0' => [
			'query' => 'SELECT 1 LIMIT 0',
			'expected range' => QueryResultRowCountRange::createFixed(0),
		];

		$uncertainFalseConds = [
			'-5 + 5',
			'5 - 5',
			'0 / 5',
			'0 DIV 5',
			'0 MOD 5',
			'0 % 5',
			'0 & 5',
			'0 | 0',
			'0 << 5',
			'0 >> 5',
			'5 ^ 5',
			'~~0',
			'5 = 1',
			'5 != 5',
			'5 < 1',
			'5 <= 1',
			'1 > 5',
			'1 >= 5',
			'1 AND 1',
		];

		foreach ($uncertainFalseConds as $cond) {
			$query = "SELECT 1 WHERE ({$cond})";

			yield $query => [
				'query' => $query,
				// TODO: make this fixed 0 once the condition analysis improves
				'expected range' => new QueryResultRowCountRange(0, 1),
			];

			$query = "SELECT 1 WHERE NOT ({$cond})";

			yield $query => [
				'query' => $query,
				// TODO: make this fixed 1 once the condition analysis improves
				'expected range' => new QueryResultRowCountRange(0, 1),
			];
		}
	}

	/**
	 * @dataProvider provideTestRowCountRangeData
	 * @param array<scalar|null> $params
	 */
	public function testRowCountRange(
		string $query,
		?QueryResultRowCountRange $expectedRange,
		array $params = [],
	): void {
		$db = TestCaseHelper::getDefaultSharedConnection();
		$analyser = TestCaseHelper::createAnalyser();
		$result = $analyser->analyzeQuery($query);
		$this->assertEquals($expectedRange, $result->rowCountRange);
		$db->begin_transaction();

		try {
			$stmt = null;

			if (count($params) > 0) {
				$stmt = $db->prepare($query);
				$stmt->execute($params);
				$dbResult = $stmt->get_result();
			} else {
				$dbResult = $db->query($query);
			}

			$warningArr = $this->getRelevantWarnings($db);

			$this->assertInstanceOf(mysqli_result::class, $dbResult);
			$rows = $dbResult->fetch_all(MYSQLI_NUM);
			$dbResult->close();
			$stmt?->close();
		} finally {
			$db->rollback();
		}

		if (count($warningArr) > 0) {
			$this->fail("Warnings: " . implode("\n", $warningArr));
		}

		if ($expectedRange === null) {
			return;
		}

		$this->assertGreaterThanOrEqual($expectedRange->min, count($rows));

		if ($expectedRange->max === null) {
			return;
		}

		$this->assertLessThanOrEqual($expectedRange->max, count($rows));
	}

	public function testPlaceholderTypeProvider(): void
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$analyser = TestCaseHelper::createAnalyser();
		$query = 'SELECT ?, ?, ?';
		$params = array_fill(0, 3, 1);
		$paramTypeString = 'ids';
		$result = $analyser->analyzeQuery($query, new class implements PlaceholderTypeProvider {
			public function getPlaceholderDbType(Placeholder $placeholder): DbType
			{
				return match ($placeholder->name) {
					1 => new IntType(),
					2 => new FloatType(),
					3 => new VarcharType(),
					default => AnalyserTest::fail("Unhandled placeholder: {$placeholder->name}"),
				};
			}

			public function isPlaceholderNullable(Placeholder $placeholder): bool
			{
				return $placeholder->name === 2;
			}
		});

		$this->assertSame(count($params), $result->positionalPlaceholderCount);
		$db->begin_transaction();

		try {
			$stmt = $db->prepare($query);
			$stmt->bind_param($paramTypeString, ...$params);
			$stmt->execute();
			$dbResult = $stmt->get_result();
			$warningArr = $this->getRelevantWarnings($db);

			$this->assertInstanceOf(mysqli_result::class, $dbResult);
			$fields = $dbResult->fetch_fields();
			$rows = $dbResult->fetch_all(MYSQLI_NUM);
			$dbResult->close();
			$stmt->close();
		} finally {
			$db->rollback();
		}

		if (count($warningArr) > 0) {
			$this->fail("Warnings: " . implode("\n", $warningArr));
		}

		$this->assertNotNull($result->resultFields);
		$this->assertSameSize($result->resultFields, $fields);
		$this->assertCount(1, $rows);
		$firstRow = $rows[0];
		$this->assertIsArray($firstRow);

		$this->assertSame(DbTypeEnum::INT, $result->resultFields[0]->exprType->type::getTypeEnum());
		$this->assertSame(DbTypeEnum::FLOAT, $result->resultFields[1]->exprType->type::getTypeEnum());
		$this->assertSame(DbTypeEnum::VARCHAR, $result->resultFields[2]->exprType->type::getTypeEnum());

		$this->assertFalse($result->resultFields[0]->exprType->isNullable);
		$this->assertTrue($result->resultFields[1]->exprType->isNullable);
		$this->assertFalse($result->resultFields[2]->exprType->isNullable);

		$this->assertIsInt($firstRow[0]);
		$this->assertIsFloat($firstRow[1]);
		$this->assertIsString($firstRow[2]);
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

	private function mysqliTypeToDbTypeEnum(int $type, int $flags): DbTypeEnum
	{
		return match ($type) {
			MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL => DbTypeEnum::DECIMAL,
			MYSQLI_TYPE_TINY /* =  MYSQLI_TYPE_CHAR */, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_INT24, MYSQLI_TYPE_LONG,
				MYSQLI_TYPE_LONGLONG => in_array('UNSIGNED', MysqliUtil::getFlagNames($flags), true)
					? DbTypeEnum::UNSIGNED_INT
					: DbTypeEnum::INT,
			MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE => DbTypeEnum::FLOAT,
			MYSQLI_TYPE_TIMESTAMP, MYSQLI_TYPE_DATE, MYSQLI_TYPE_TIME, MYSQLI_TYPE_DATETIME, MYSQLI_TYPE_YEAR
				=> DbTypeEnum::DATETIME,
			MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_STRING => DbTypeEnum::VARCHAR,
			MYSQLI_TYPE_NULL => DbTypeEnum::NULL,
			MYSQLI_TYPE_BLOB => DbTypeEnum::VARCHAR,
			// TODO: MYSQLI_TYPE_ENUM, MYSQLI_TYPE_BIT, MYSQLI_TYPE_INTERVAL, MYSQLI_TYPE_SET,
			// MYSQLI_TYPE_GEOMETRY, MYSQLI_TYPE_JSON, blob/binary types
			default => throw new \RuntimeException("Unhandled type {$type}"),
		};
	}

	private static function createMockTuple(int $count): TupleType
	{
		$types = array_fill(0, $count, new IntType());

		return new TupleType($types, false);
	}

	private function getFieldNameForErrorMessage(QueryResultField $field): string
	{
		$name = $field->name;

		if ($field->exprType->column !== null) {
			$name .= " ({$field->exprType->column->tableAlias}.{$field->exprType->column->name})";
		}

		return $name;
	}

	/** @return array<string> */
	private function getRelevantWarnings(\mysqli $db): array
	{
		$warningArr = [];
		$warning = $db->get_warnings();

		if ($warning === false) {
			return $warningArr;
		}

		do {
			if (in_array($warning->errno, self::IGNORED_WARNINGS, true)) {
				continue;
			}

			$warningArr[] = "{$warning->errno}: {$warning->message}";
		} while ($warning->next());

		return $warningArr;
	}
}
