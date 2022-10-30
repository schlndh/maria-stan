<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use DateTimeImmutable;
use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Query\SelectQueryCombinatorTypeEnum;
use MariaStan\DatabaseTestCaseHelper;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\TupleType;
use MariaStan\Util\MariaDbErrorCodes;
use mysqli_result;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_string;
use function str_starts_with;

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
	public function provideValidTestData(): iterable
	{
		$tableName = 'analyser_test';
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(255) NULL
			);
		");
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

		yield from $this->provideValidLiteralTestData();
		yield from $this->provideValidOperatorTestData();
		yield from $this->provideValidDataTypeTestData();
		yield from $this->provideValidJoinTestData();
		yield from $this->provideValidSubqueryTestData();
		yield from $this->provideValidGroupByHavingOrderTestData();
		yield from $this->provideValidPlaceholderTestData();
		yield from $this->provideValidFunctionCallTestData();
		yield from $this->provideValidUnionTestData();
		yield from $this->provideValidWithTestData();
		yield from $this->provideValidInsertTestData();
		yield from $this->provideValidOtherQueryTestData();
		yield from $this->provideValidUpdateTestData();
		yield from $this->provideValidDeleteTestData();
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidLiteralTestData(): iterable
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
	private function provideValidDataTypeTestData(): iterable
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
	private function provideValidJoinTestData(): iterable
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
	private function provideValidOperatorTestData(): iterable
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

		$caseValues = ['1', '1.1', '1.1e1', '"a"', 'NULL'];

		foreach ($caseValues as $value1) {
			foreach ($caseValues as $value2) {
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
		];

		foreach ($exprs as $expr) {
			yield "operator {$expr}" => [
				'query' => "SELECT {$expr}",
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidSubqueryTestData(): iterable
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

		yield 'subquery as SELECT expression - reference previously aliased field' => [
			'query' => 'SELECT 1 aaa, (SELECT aaa)',
		];

		yield 'subquery - previously aliased field vs column - field list' => [
			'query' => 'SELECT "aa" id, (SELECT id) FROM analyser_test',
		];

		yield 'subquery - previously aliased field vs column - WHERE' => [
			'query' => 'SELECT "aa" id FROM analyser_test WHERE (SELECT id) = 1',
		];

		yield 'subquery - previously aliased field vs column - GROUP BY' => [
			'query' => 'SELECT "aa" id FROM analyser_test GROUP BY (SELECT id)',
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
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidGroupByHavingOrderTestData(): iterable
	{
		yield 'use alias from field list in GROUP BY' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test GROUP BY aaa',
		];

		yield 'use column in GROUP BY - same alias in field list' => [
			'query' => 'SELECT *, 1+1 id FROM analyser_test GROUP BY id',
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

		yield 'use columns from GROUP BY in HAVING' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test GROUP BY name HAVING name',
		];

		yield 'use any column in HAVING as part of an aggregate' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test HAVING COUNT(name)',
		];

		yield 'use alias from field list in ORDER BY' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test ORDER BY aaa',
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidPlaceholderTestData(): iterable
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
	private function provideValidFunctionCallTestData(): iterable
	{
		$tableName = 'analyser_test';

		$selects = [
			'COUNT all' => "SELECT COUNT(*) FROM {$tableName}",
			'COUNT column' => "SELECT COUNT(id) FROM {$tableName}",
			'COUNT DISTINCT - single column' => "SELECT COUNT(DISTINCT id) FROM {$tableName}",
			'COUNT DISTINCT - multiple columns' => "SELECT COUNT(DISTINCT id, name) FROM {$tableName}",
			'AVG DISTINCT' => "SELECT COUNT(DISTINCT id, name) FROM {$tableName}",
		];

		foreach (['AVG', 'MAX', 'MIN', 'SUM'] as $fn) {
			$selects["{$fn}"] = "SELECT {$fn}(id) FROM {$tableName}";
			$selects["{$fn} DISTINCT"] = "SELECT {$fn}(DISTINCT id) FROM {$tableName}";
		}

		foreach ($selects as $label => $select) {
			yield $label => [
				'query' => $select,
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidUnionTestData(): iterable
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
				'int' => '1',
				'string' => '"a"',
				'decimal' => '1.2',
				'float' => '1.2e3',
				'null' => 'NULL',
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
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidWithTestData(): iterable
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
	private function provideValidOtherQueryTestData(): iterable
	{
		yield "TRUNCATE TABLE" => [
			'query' => 'TRUNCATE TABLE analyser_test_truncate',
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidInsertTestData(): iterable
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

		// TODO: DEFAULT expression
	}

	/** @return iterable<string, array<mixed>> */
	private function provideValidUpdateTestData(): iterable
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
	private function provideValidDeleteTestData(): iterable
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
	 */
	public function testValid(string $query, array $params = []): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db, $parser);
		$analyser = new Analyser($parser, $reflection);
		$result = $analyser->analyzeQuery($query);
		$isUnhanhledExpressionTypeError = static fn (AnalyserError $e) => str_starts_with(
			$e->message,
			'Unhandled expression type',
		);
		$unhandledExpressionTypeErrors = array_filter($result->errors, $isUnhanhledExpressionTypeError);
		$isUnhandledFunctionError = static fn (AnalyserError $e) => str_starts_with($e->message, 'Unhandled function:');
		$unhandledFunctionErrors = array_filter($result->errors, $isUnhandledFunctionError);
		$otherErrors = array_filter(
			$result->errors,
			static fn (AnalyserError $e) => ! $isUnhanhledExpressionTypeError($e) && ! $isUnhandledFunctionError($e),
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
			$this->assertSame($analyzedField->name, $field->name);
			$isFieldNullable = ! ($field->flags & MYSQLI_NOT_NULL_FLAG);

			if ($analyzedField->isNullable && ! $isFieldNullable) {
				$unnecessaryNullableFields[] = $analyzedField->name;
			} elseif (! $analyzedField->isNullable && $isFieldNullable) {
				$forceNullsForColumns[$field->name] = false;
			}

			$actualType = $this->mysqliTypeToDbTypeEnum($field->type);

			// It seems that in some cases the type returned by the database does not propagate NULL in all cases.
			// E.g. 1 + NULL is double for some reason. Let's allow the analyser to get away with null, but force
			// check that the returned values are all null.
			if ($analyzedField->type::getTypeEnum() === DbTypeEnum::NULL && $field->type !== MYSQLI_TYPE_NULL) {
				$forceNullsForColumns[$field->name] = true;
			} elseif ($analyzedField->type::getTypeEnum() === DbTypeEnum::ENUM) {
				$this->assertTrue(($field->flags & MYSQLI_ENUM_FLAG) !== 0);
				$this->assertSame(DbTypeEnum::VARCHAR, $actualType);
			} elseif ($analyzedField->type::getTypeEnum() === DbTypeEnum::DATETIME) {
				if ($actualType === DbTypeEnum::VARCHAR) {
					$datetimeFields[] = $i;
				} else {
					$this->assertSame($actualType, $analyzedField->type::getTypeEnum());
				}
			} elseif ($analyzedField->type::getTypeEnum() === DbTypeEnum::MIXED) {
				$mixedFieldErrors[] = "DB type for {$analyzedField->name} should be {$actualType->value} got MIXED.";
			} else {
				$this->assertSame(
					$analyzedField->type::getTypeEnum(),
					$actualType,
					"The test says {$analyzedField->name} should be {$analyzedField->type::getTypeEnum()->name} "
					. "but got {$actualType->name} from the database.",
				);
			}
		}

		foreach ($rows as $row) {
			$this->assertSame($fieldKeys, array_keys($row));

			foreach ($forceNullsForColumns as $col => $mustBeNull) {
				if ($mustBeNull) {
					$this->assertNull($row[$col]);
				} else {
					$this->assertNotNull($row[$col]);
				}
			}

			$colNames = array_keys($row);

			foreach ($datetimeFields as $colIdx) {
				$val = $row[$colNames[$colIdx]];

				if ($val === null) {
					continue;
				}

				$parsedDateTime = false;

				foreach (['Y-m-d H:i:s', 'Y-m-d'] as $format) {
					$parsedDateTime = $parsedDateTime ?: DateTimeImmutable::createFromFormat($format, $val);
				}

				$this->assertNotFalse($parsedDateTime);
			}
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

		if (count($unhandledFunctionErrors) > 0) {
			$incompleteTestErrors[] = "There are functions:\n"
				. implode(
					",\n",
					array_map(static fn (AnalyserError $e) => $e->message, $unhandledFunctionErrors),
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
	public function provideInvalidTestData(): iterable
	{
		// TODO: improve the error messages to match MariaDB errors more closely.
		yield 'usage of previous alias in field list' => [
			'query' => 'SELECT 1+1 aaa, aaa + 1 FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'subquery - forward reference to alias in field list' => [
			'query' => 'SELECT (SELECT aaa), 1 aaa',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_REFERENCE,
		];

		yield 'subquery - reference field alias in WHERE' => [
			'query' => 'SELECT 1 aaa WHERE (SELECT aaa) = 1',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'not unique table name in top-level query' => [
			'query' => 'SELECT * FROM analyser_test, analyser_test',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('analyser_test'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique table alias in top-level query' => [
			'query' => 'SELECT * FROM analyser_test t, analyser_test t',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('t'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique subquery alias' => [
			'query' => 'SELECT * FROM (SELECT 1) t, (SELECT 1) t',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('t'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique table alias - nested JOIN' => [
			'query' => 'SELECT * FROM (analyser_test a, analyser_test b) JOIN (analyser_test a, analyser_test c)',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('a'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique table - nested JOIN' => [
			'query' => 'SELECT * FROM (analyser_test, (SELECT 1) b) JOIN (analyser_test, (SELECT 2) c)',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('analyser_test'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique table in subquery' => [
			'query' => 'SELECT * FROM (SELECT 1 FROM analyser_test, analyser_test) t',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('analyser_test'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'not unique table alias in WITH' => [
			'query' => 'WITH tbl AS (SELECT 1), tbl AS (SELECT 1) SELECT * FROM tbl',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('tbl'),
			'DB error code' => MariaDbErrorCodes::ER_DUP_QUERY_NAME,
		];

		yield 'duplicate column name in subquery' => [
			'query' => 'SELECT * FROM (SELECT * FROM analyser_test a, analyser_test b) t',
			'error' => AnalyserErrorMessageBuilder::createDuplicateColumnName('id'),
			'DB error code' => MariaDbErrorCodes::ER_DUP_FIELDNAME,
		];

		yield 'duplicate column name in WITH' => [
			'query' => 'WITH analyser_test AS (SELECT 1, 1) SELECT * FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createDuplicateColumnName('1'),
			'DB error code' => MariaDbErrorCodes::ER_DUP_FIELDNAME,
		];

		yield 'duplicate column name in WITH - explicit column list' => [
			'query' => 'WITH tbl (id, id) AS (SELECT 1, 2) SELECT 1',
			'error' => AnalyserErrorMessageBuilder::createDuplicateColumnName('id'),
			'DB error code' => MariaDbErrorCodes::ER_DUP_FIELDNAME,
		];

		yield 'WITH - mismatched number of columns in column list' => [
			'query' => 'WITH tbl (id, aa) AS (SELECT 1) SELECT * FROM tbl',
			'error' => AnalyserErrorMessageBuilder::createDifferentNumberOfWithColumnsErrorMessage(2, 1),
			'DB error code' => MariaDbErrorCodes::ER_WITH_COL_WRONG_LIST,
		];

		yield "LIKE - multichar ESCAPE literal" => [
			'query' => "SELECT 'a' LIKE 'b' ESCAPE 'cd'",
			'error' => AnalyserErrorMessageBuilder::createInvalidLikeEscapeMulticharErrorMessage('cd'),
			'DB error code' => MariaDbErrorCodes::ER_WRONG_ARGUMENTS,
		];

		yield 'mismatched arguments' => [
			'query' => 'SELECT AVG(id, name) FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createMismatchedFunctionArgumentsErrorMessage(
				'AVG',
				2,
				[1],
			),
			'DB error code' => MariaDbErrorCodes::ER_PARSE_ERROR,
		];

		yield 'bug - valid subquery should not clear errors from parent query' => [
			'query' => 'SELECT v.id, (SELECT id FROM analyser_test LIMIT 1) aa FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield from $this->provideInvalidColumnTestData();
		yield from $this->provideInvalidTupleTestData();
		yield from $this->provideInvalidUnionTestData();
		yield from $this->provideInvalidInsertTestData();
		yield from $this->provideInvalidOtherQueryTestData();
		yield from $this->provideInvalidUpdateTestData();
		yield from $this->provideInvalidDeleteTestData();
	}

	/** @return iterable<string, array<mixed>> */
	private function provideInvalidColumnTestData(): iterable
	{
		yield 'unknown column in field list' => [
			'query' => 'SELECT v.id FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in field list' => [
			'query' => 'SELECT (SELECT v.id FROM analyser_test)',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in FROM' => [
			'query' => 'SELECT * FROM (SELECT v.id FROM analyser_test) t JOIN analyser_test v',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in subquery in EXISTS' => [
			'query' => 'SELECT EXISTS (SELECT v.id FROM analyser_test)',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - IS' => [
			'query' => 'SELECT v.id IS NULL FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - LIKE - left' => [
			'query' => 'SELECT v.id LIKE "a" FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - LIKE - right' => [
			'query' => 'SELECT "a" LIKE v.id FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in field list - WITH' => [
			'query' => 'WITH tbl AS (SELECT aaa FROM analyser_test) SELECT * FROM tbl',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'ambiguous column in field list' => [
			'query' => 'SELECT id FROM analyser_test a, analyser_test b',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'unknown column in WHERE' => [
			'query' => 'SELECT * FROM analyser_test WHERE v.id',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'using field list alias in WHERE' => [
			'query' => 'SELECT 1+1 aaa FROM analyser_test WHERE aaa',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in GROUP BY' => [
			'query' => 'SELECT * FROM analyser_test GROUP BY v.id',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'ambiguous column in GROUP BY' => [
			'query' => 'SELECT a.id, b.id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in GROUP BY - explicit alias' => [
			'query' => 'SELECT a.id, b.name id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in GROUP BY - *, expression alias' => [
			'query' => 'SELECT *, 1+1 id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in GROUP BY - a.id, b.id, 1+1 id' => [
			'query' => 'SELECT a.id, b.id, 1+1 id FROM analyser_test a, analyser_test b GROUP BY id',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'unknown column in HAVING' => [
			'query' => 'SELECT * FROM analyser_test HAVING v.id',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column as function argument' => [
			'query' => 'SELECT AVG(v.id) FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		// TODO: implement this
		//yield 'unknown column in HAVING - not in field list nor in GROUP BY nor aggregate' => [
		//	'query' => 'SELECT 1 FROM analyser_test GROUP BY id HAVING name',
		//	'error' => 'Unknown column name',
		//	'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		//];

		yield 'unknown column in ORDER BY' => [
			'query' => 'SELECT * FROM analyser_test ORDER BY v.id',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in INTERVAL' => [
			'query' => 'SELECT "2022-08-27" - INTERVAL v.id DAY FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown table in JOIN' => [
			'query' => 'SELECT * FROM analyser_test JOIN aaabbbccc',
			'error' => AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('aaabbbccc'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield 'subquery - cannot use alias from outer query in FROM' => [
			'query' => 'SELECT (SELECT id FROM t) FROM analyser_test t',
			'error' => AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('t'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield 'unknown column in JOIN ... ON' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b ON a.id = b.aaa',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa', 'b'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... ON - referring to table joined later' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b ON b.id = c.id JOIN analyser_test c',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'c'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... ON - JOIN has higher precedence than comma' => [
			'query' => 'SELECT * FROM analyser_test a, analyser_test b JOIN analyser_test c ON a.id = c.id ',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'a'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... USING - JOIN has higher precedence than comma' => [
			'query' => 'SELECT * FROM (SELECT 1 aa) a, (SELECT 2 bb) b JOIN (SELECT 1 aa, 2 bb) c USING (aa)',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... USING - column exists only on one side - right' => [
			'query' => 'SELECT * FROM (SELECT 1 aa) a JOIN (SELECT 2 bb) b using (bb)',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('bb'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in JOIN ... USING - column exists only on one side - left' => [
			'query' => 'SELECT * FROM (SELECT 1 aa) a JOIN (SELECT 2 bb) b using (aa)',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'ambiguous column in JOIN ... USING' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b JOIN analyser_test c USING (id)',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
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
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('cc'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column in JOIN ... ON' => [
			'query' => 'SELECT * FROM analyser_test a JOIN analyser_test b ON a.id = id',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'ambiguous column as function argument' => [
			'query' => 'SELECT AVG(id) FROM analyser_test a, analyser_test b',
			'error' => AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('id'),
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield 'unknown column in tuple' => [
			'query' => 'SELECT (id, name) = (id, aaa) FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE' => [
			'query' => 'SELECT CASE aaa WHEN 1 THEN 1 END FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE WHEN' => [
			'query' => 'SELECT CASE 1 WHEN aaa THEN 1 END FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE THEN' => [
			'query' => 'SELECT CASE 1 WHEN 1 THEN aaa END FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'unknown column in CASE ELSE' => [
			'query' => 'SELECT CASE 1 WHEN 1 THEN 1 ELSE aaa END FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aaa'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideInvalidTupleTestData(): iterable
	{
		yield 'tuple size does not match' => [
			'query' => 'SELECT (id, name, 1) = (id, name) FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage(
				$this->createMockTuple(3),
				$this->createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield 'tuple: single value vs multi-column SELECT' => [
			'query' => 'SELECT 1 IN (SELECT 1, 2)',
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage(
				new IntType(),
				$this->createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield 'tuple: flat tuple both on left and right with IN' => [
			'query' => 'SELECT (1, 2) IN (1, 2)',
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage(
				$this->createMockTuple(2),
				new IntType(),
			),
			'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION,
		];

		yield 'tuple: (tuple) IN (SELECT ..., SELECT ...)' => [
			'query' =>
				'SELECT (1,2) IN ((SELECT id FROM analyser_test LIMIT 1), (SELECT id FROM analyser_test LIMIT 1))',
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage(
				$this->createMockTuple(2),
				new IntType(),
			),
			'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION,
		];

		yield 'tuple: nested tuples with IN and missing parentheses on right' => [
			// This works if right side is wrapped in one more parentheses
			'query' => 'SELECT ((1,2), 3) IN ((1,2), 3)',
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage(
				$this->createMockTuple(2),
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
					'error' => AnalyserErrorMessageBuilder::createInvalidBinaryOpUsageErrorMessage(
						BinaryOpTypeEnum::from($invalidOperator),
						DbTypeEnum::TUPLE,
						DbTypeEnum::TUPLE,
					),
					'DB error code' => $mariadbErrorCode,
				];

				yield "invalid operator with tuples - tuple {$invalidOperator} 1" => [
					'query' => "SELECT (id, name, 1) {$invalidOperator} 1 FROM analyser_test",
					'error' => AnalyserErrorMessageBuilder::createInvalidBinaryOpUsageErrorMessage(
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
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleUsageErrorMessage(
				$this->createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - CASE tuple WHEN tuple" => [
			'query' => "SELECT CASE (1, 1) WHEN (1,1) THEN 1 END",
			'error' => [
				AnalyserErrorMessageBuilder::createInvalidTupleUsageErrorMessage(
					$this->createMockTuple(2),
				),
				AnalyserErrorMessageBuilder::createInvalidTupleUsageErrorMessage(
					$this->createMockTuple(2),
				),
			],
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - CASE THEN" => [
			'query' => "SELECT CASE WHEN 1 THEN (1,1) END",
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleUsageErrorMessage(
				$this->createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - CASE ELSE" => [
			'query' => "SELECT CASE WHEN 1 THEN 0 ELSE (1,1) END",
			'error' => AnalyserErrorMessageBuilder::createInvalidTupleUsageErrorMessage(
				$this->createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - LIKE" => [
			'query' => "SELECT (id, name, 1) LIKE (1, 'aa') FROM analyser_test",
			'error' => AnalyserErrorMessageBuilder::createInvalidLikeUsageErrorMessage(
				DbTypeEnum::TUPLE,
				DbTypeEnum::TUPLE,
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - LIKE - tuple in escape char" => [
			'query' => "SELECT name LIKE 'a' ESCAPE (1, 2) FROM analyser_test",
			'error' => AnalyserErrorMessageBuilder::createInvalidLikeUsageErrorMessage(
				DbTypeEnum::VARCHAR,
				DbTypeEnum::VARCHAR,
				DbTypeEnum::TUPLE,
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield "invalid operator with tuples - tuple LIKE 1" => [
			'query' => "SELECT (id, name, 1) LIKE 1 FROM analyser_test",
			'error' => AnalyserErrorMessageBuilder::createInvalidLikeUsageErrorMessage(
				DbTypeEnum::TUPLE,
				DbTypeEnum::INT,
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];

		yield 'tuple as function argument' => [
			'query' => 'SELECT AVG((id, name)) FROM analyser_test',
			'error' => AnalyserErrorMessageBuilder::createInvalidFunctionArgumentErrorMessage(
				'AVG',
				1,
				$this->createMockTuple(2),
			),
			'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideInvalidUnionTestData(): iterable
	{
		foreach (SelectQueryCombinatorTypeEnum::cases() as $combinator) {
			$combinatorVal = $combinator->value;

			yield "{$combinatorVal} - error in left query" => [
				'query' => "SELECT v.id FROM analyser_test {$combinatorVal} SELECT id FROM analyser_test",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - error in right query" => [
				'query' => "SELECT id FROM analyser_test {$combinatorVal} SELECT v.id FROM analyser_test",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - error in nested query" => [
				'query' => "
					SELECT id FROM analyser_test
					{$combinatorVal}
					SELECT v.id FROM analyser_test
					{$combinatorVal} SELECT 1
				",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'v'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - error when used as subquery in FROM" => [
				'query' => "SELECT * FROM (SELECT 1 {$combinatorVal} SELECT 2, 3) t",
				'error' => AnalyserErrorMessageBuilder::createDifferentNumberOfColumnsErrorMessage(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_NUMBER_OF_COLUMNS_IN_SELECT,
			];

			yield "{$combinatorVal} - error when used as subquery expression" => [
				'query' => "SELECT 1 IN (SELECT 1 {$combinatorVal} SELECT 2, 3)",
				'error' => AnalyserErrorMessageBuilder::createDifferentNumberOfColumnsErrorMessage(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_OPERAND_COLUMNS,
			];

			yield "{$combinatorVal} - cannot use column name from second query in ORDER BY" => [
				'query' => "SELECT 1 aa {$combinatorVal} SELECT 2 bb ORDER BY bb",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('bb'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - cannot use original name of aliased column in ORDER BY" => [
				'query' => "SELECT id aa FROM analyser_test {$combinatorVal} SELECT 2 bb ORDER BY id",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$combinatorVal} - cannot use table.column ORDER BY" => [
				'query' => "
					SELECT id FROM analyser_test
					{$combinatorVal}
					SELECT id FROM analyser_test
					ORDER BY analyser_test.id
				",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('id', 'analyser_test'),
				'DB error code' => MariaDbErrorCodes::ER_TABLENAME_NOT_ALLOWED_HERE,
			];

			yield "{$combinatorVal} - different number of columns" => [
				'query' => "SELECT 1 {$combinatorVal} SELECT 2, 3",
				'error' => AnalyserErrorMessageBuilder::createDifferentNumberOfColumnsErrorMessage(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_NUMBER_OF_COLUMNS_IN_SELECT,
			];

			yield "{$combinatorVal} - cannot use columns from first query in second query" => [
				'query' => "SELECT id aa FROM analyser_test {$combinatorVal} SELECT 2 + aa",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('aa'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];
		}
	}

	/** @return iterable<string, array<mixed>> */
	private function provideInvalidOtherQueryTestData(): iterable
	{
		yield "TRUNCATE missing_table" => [
			'query' => "TRUNCATE missing_table",
			'error' => [
				AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('missing_table'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideInvalidInsertTestData(): iterable
	{
		foreach (['INSERT', 'REPLACE'] as $type) {
			yield "{$type} INTO missing_table" => [
				'query' => "{$type} INTO missing_table SET col = 'value'",
				'error' => [
					AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('missing_table'),
					AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('col'),
				],
				'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
			];

			yield "{$type} INTO ... SET missing_column" => [
				'query' => "{$type} INTO analyser_test SET missing_column = 'value'",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_column'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$type} INTO ... (missing_column) VALUES ..." => [
				'query' => "{$type} INTO analyser_test (id, name, missing_column) VALUES (999, 'adasd', 1)",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_column'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$type} INTO ... (missing_column) SELECT ..." => [
				'query' => "{$type} INTO analyser_test (id, name, missing_column) SELECT 999, 'adasd', 1",
				'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_column'),
				'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
			];

			yield "{$type} INTO ... SELECT - issue in SELECT" => [
				'query' => "{$type} INTO analyser_test SELECT * FROM missing_table",
				'error' => AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('missing_table'),
				'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
			];

			yield "{$type} INTO ... VALUES - issue in tuple" => [
				'query' => "{$type} INTO analyser_test (name) VALUES (1 = (1, 2))",
				'error' => AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage(
					new IntType(),
					$this->createMockTuple(2),
				),
				'DB error code' => MariaDbErrorCodes::ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999, 'adasd', 1)",
				'error' => AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count - in some tuples" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999, 'adasd'), (998, 'aaa', 1)",
				'error' => AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count - in multiple tuples" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999), (998, 'aaa', 1)",
				'error' => AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(2, 1),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count + error in tuple" => [
				'query' => "{$type} INTO analyser_test (id, name) VALUES (999, 'adasd'), (998, 'aaa', 1),"
					. " (111, 1 = (1, 1))",
				'error' => [
					AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage(
						new IntType(),
						$this->createMockTuple(2),
					),
					AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(2, 3),
				],
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) VALUES ... - mismatched column count - implicit column list" => [
				'query' => "{$type} INTO analyser_test VALUES (999, 'adasd', 1)",
				'error' => AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) SELECT ... - mismatched column count" => [
				'query' => "{$type} INTO analyser_test (name) SELECT 'adasd', 1",
				'error' => AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(1, 2),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} INTO ... (columns, ...) SELECT ... - mismatched column count - implicit column list" => [
				'query' => "{$type} INTO analyser_test SELECT 999, 'adasd', 1",
				'error' => AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(2, 3),
				'DB error code' => MariaDbErrorCodes::ER_WRONG_VALUE_COUNT_ON_ROW,
			];

			yield "{$type} ... VALUES - skip column without default value" => [
				'query' => "{$type} INTO analyse_test_insert (val_string_null_default) VALUES ('aaa')",
				'error' => AnalyserErrorMessageBuilder::createMissingValueForColumnErrorMessage(
					'val_string_not_null_no_default',
				),
				'DB error code' => MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD,
			];

			yield "{$type} ... SET - skip column without default value" => [
				'query' => "{$type} INTO analyse_test_insert SET val_string_null_default = 'aaa'",
				'error' => AnalyserErrorMessageBuilder::createMissingValueForColumnErrorMessage(
					'val_string_not_null_no_default',
				),
				'DB error code' => MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD,
			];

			yield "{$type} ... SELECT - skip column without default value" => [
				'query' => "{$type} INTO analyse_test_insert (val_string_null_default) SELECT 'aaa'",
				'error' => AnalyserErrorMessageBuilder::createMissingValueForColumnErrorMessage(
					'val_string_not_null_no_default',
				),
				'DB error code' => MariaDbErrorCodes::ER_NO_DEFAULT_FOR_FIELD,
			];
		}

		yield "INSERT INTO ... ON DUPLICATE KEY UPDATE missing_column" => [
			'query' => "
				INSERT INTO analyser_test (id, name) SELECT 999, 'adasd'
				ON DUPLICATE KEY UPDATE missing_column = 1
			",
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_column'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideInvalidUpdateTestData(): iterable
	{
		yield "UPDATE missing_table" => [
			'query' => "UPDATE missing_table SET col = 'value'",
			'error' => [
				AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('missing_table'),
				AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield "UPDATE ... SET missing_col = ..." => [
			'query' => "UPDATE analyser_test SET missing_col = 1",
			'error' => [
				AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield "UPDATE ... SET ambiguous_col" => [
			'query' => "UPDATE analyser_test t1, analyser_test t2 SET name = 'aa'",
			'error' => [
				AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage('name'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NON_UNIQ_ERROR,
		];

		yield "UPDATE - not unique table/alias" => [
			'query' => "UPDATE analyser_test, analyser_test SET name = 'aa'",
			'error' => [
				AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('analyser_test'),
				AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('name'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield "UPDATE - error in table reference subquery" => [
			'query' => "UPDATE analyser_test t1, (SELECT * FROM missing_table) t2 SET t1.name = 'aa'",
			'error' => [
				AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('missing_table'),
			],
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield "UPDATE - error in SET expression" => [
			'query' => "UPDATE analyser_test SET name = missing_col + 5",
			'error' => [
				AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield "UPDATE - error in WHERE expression" => [
			'query' => "UPDATE analyser_test SET name = 'aa' WHERE missing_col > 5",
			'error' => [
				AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield "UPDATE - error in ORDER BY expression" => [
			'query' => "UPDATE analyser_test SET name = 'aa' ORDER BY missing_col > 5",
			'error' => [
				AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
			],
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		// TODO: detect this
		//yield "UPDATE - trying to update subquery" => [
		//	'query' => "UPDATE (SELECT * FROM analyser_test) t SET name = 'aa'",
		//	'error' => [
		//		AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
		//	],
		//	'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		//];
		//
		//yield "UPDATE - trying to update subquery - with normal table as well" => [
		//	'query' => "UPDATE (SELECT 1 aaa) t, analyser_test SET name = 'aaa', aaa = 2",
		//	'error' => [
		//		AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
		//	],
		//	'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		//];
	}

	/** @return iterable<string, array<mixed>> */
	private function provideInvalidDeleteTestData(): iterable
	{
		yield 'DELETE - missing table' => [
			'query' => 'DELETE FROM missing_table',
			'error' => AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('missing_table'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		yield 'DELETE - wrong alias' => [
			'query' => 'DELETE t_miss FROM analyser_test_truncate t1',
			'error' => AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('t_miss'),
			'DB error code' => MariaDbErrorCodes::ER_UNKNOWN_TABLE,
		];

		yield 'DELETE - by table name despite alias' => [
			'query' => 'DELETE analyser_test_truncate FROM analyser_test_truncate t1',
			'error' => AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('analyser_test_truncate'),
			'DB error code' => MariaDbErrorCodes::ER_UNKNOWN_TABLE,
		];

		yield 'DELETE - error in WHERE' => [
			'query' => 'DELETE FROM analyser_test_truncate WHERE missing_col > 1',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'DELETE - error in ORDER BY' => [
			'query' => 'DELETE FROM analyser_test_truncate ORDER BY missing_col',
			'error' => AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage('missing_col'),
			'DB error code' => MariaDbErrorCodes::ER_BAD_FIELD_ERROR,
		];

		yield 'DELETE - error in subquery' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1, (SELECT * FROM missing_table) t2',
			'error' => AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage('missing_table'),
			'DB error code' => MariaDbErrorCodes::ER_NO_SUCH_TABLE,
		];

		// having the same alias for table and subquery works in SELECT
		yield 'DELETE - duplicate alias - subquery - alias' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1, (SELECT 1) t1',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('t1'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'DELETE - duplicate alias - subquery - table name' => [
			'query' => 'DELETE analyser_test_truncate FROM analyser_test_truncate, (SELECT 1) analyser_test_truncate',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('analyser_test_truncate'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		yield 'DELETE - duplicate alias - table' => [
			'query' => 'DELETE t1 FROM analyser_test_truncate t1, analyser_test_truncate t1',
			'error' => AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage('t1'),
			'DB error code' => MariaDbErrorCodes::ER_NONUNIQ_TABLE,
		];

		// TODO: detect deleting non-tables
	}

	/**
	 * @param string|array<string> $error
	 * @dataProvider provideInvalidTestData
	 */
	public function testInvalid(string $query, string|array $error, int $dbErrorCode): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db, $parser);
		$analyser = new Analyser($parser, $reflection);
		$result = $analyser->analyzeQuery($query);

		if (is_string($error)) {
			$error = [$error];
		}

		$this->assertSame($error, array_map(static fn (AnalyserError $e) => $e->message, $result->errors));
		$db->begin_transaction();

		try {
			$db->query($query);
			$this->fail('Expected mysqli_sql_exception.');
		} catch (mysqli_sql_exception $e) {
			$this->assertSame($dbErrorCode, $e->getCode());
		} finally {
			$db->rollback();
		}
	}

	/** @return iterable<string, array<mixed>> */
	public function provideTestPositionPlaceholderCountData(): iterable
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
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db, $parser);
		$analyser = new Analyser($parser, $reflection);
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

	private function createMockTuple(int $count): TupleType
	{
		$types = array_fill(0, $count, $this->createMock(DbType::class));

		return new TupleType($types, false);
	}
}
