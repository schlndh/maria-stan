<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\ReferencedSymbol\ReferencedSymbol;
use MariaStan\Analyser\ReferencedSymbol\Table;
use MariaStan\Analyser\ReferencedSymbol\TableColumn;
use MariaStan\TestCaseHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnalyserReferencedSymbolTest extends TestCase
{
	/** @return iterable<string, array<mixed>> */
	public static function provideTestValidData(): iterable
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE analyser_referenced_symbol_test (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(255) NULL
			);
		");
		$db->query("
			CREATE OR REPLACE VIEW analyser_referenced_symbol_test_view
			AS SELECT * FROM analyser_referenced_symbol_test;
		");
		$db->query("INSERT INTO analyser_referenced_symbol_test (id, name) VALUES (1, 'aa'), (2, NULL)");

		yield 'SELECT 1' => [
			'query' => 'SELECT 1',
			'expectedReferencedSymbols' => [],
		];

		$table = new Table('analyser_referenced_symbol_test', TestCaseHelper::getDefaultDbName());

		$simpleTableReferences = [
			'SELECT 1 FROM analyser_referenced_symbol_test',
			'INSERT INTO analyser_referenced_symbol_test VALUES ()',
			'REPLACE INTO analyser_referenced_symbol_test VALUES ()',
			'TRUNCATE TABLE analyser_referenced_symbol_test',
			'DELETE FROM analyser_referenced_symbol_test',
		];

		foreach ($simpleTableReferences as $query) {
			yield $query => [
				'query' => $query,
				'expectedReferencedSymbols' => [$table],
			];
		}

		$simpleColumnReferences = [
			'SELECT id FROM analyser_referenced_symbol_test',
			'UPDATE analyser_referenced_symbol_test SET id = 5',
			'INSERT INTO analyser_referenced_symbol_test SET id = 5',
			'REPLACE INTO analyser_referenced_symbol_test SET id = 5',
			'DELETE FROM analyser_referenced_symbol_test WHERE id = 5',
		];

		foreach ($simpleColumnReferences as $query) {
			yield $query => [
				'query' => $query,
				'expectedReferencedSymbols' => [
					$table,
					new TableColumn($table, 'id'),
				],
			];
		}

		yield 'SELECT * FROM analyser_referenced_symbol_test' => [
			'query' => 'SELECT * FROM analyser_referenced_symbol_test',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		$view = new Table('analyser_referenced_symbol_test_view', TestCaseHelper::getDefaultDbName());

		yield 'SELECT * FROM view' => [
			'query' => 'SELECT * FROM analyser_referenced_symbol_test_view',
			'expectedReferencedSymbols' => [
				$view,
				new TableColumn($view, 'id'),
				new TableColumn($view, 'name'),
			],
		];

		yield 'SELECT * FROM CTE' => [
			'query' => 'WITH t AS (SELECT 1) SELECT * FROM t',
			'expectedReferencedSymbols' => [],
		];

		yield 'reference table twice' => [
			'query' => 'SELECT 1 FROM analyser_referenced_symbol_test, analyser_referenced_symbol_test',
			'expectedReferencedSymbols' => [$table],
		];

		yield 'reference column twice' => [
			'query' => 'SELECT id, id FROM analyser_referenced_symbol_test',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - field list' => [
			'query' => 'SELECT (SELECT id) FROM analyser_referenced_symbol_test',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - field list, semi-ambiguous' => [
			'query' => 'SELECT 1 id, (SELECT id) FROM analyser_referenced_symbol_test',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - WHERE ' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test WHERE (SELECT id) = 1',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from table - GROUP BY - ambiguous' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test GROUP BY id = 1',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - GROUP BY' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test GROUP BY (SELECT id) = 1',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from field list - HAVING' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test HAVING id = 1',
			'expectedReferencedSymbols' => [$table],
		];

		yield 'reference column from parent query - HAVING' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test HAVING (SELECT id) = 1',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference field from parent query - HAVING (SELECT WHERE)' => [
			'query' => 'SELECT "aa" id FROM analyser_referenced_symbol_test HAVING (SELECT 1 WHERE id = "aa") = 1',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference field from parent query - HAVING (SELECT GROUP BY)' => [
			'query' => '
				SELECT "aa" id
				FROM analyser_referenced_symbol_test
				HAVING (SELECT 1 FROM (SELECT 1 x UNION SELECT 2) t GROUP BY x = id) = 1
			',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference field from parent query - HAVING (SELECT HAVING)' => [
			'query' => 'SELECT "aa" id FROM analyser_referenced_symbol_test HAVING (SELECT 1 HAVING id = "aa") = 1',
			'expectedReferencedSymbols' => [$table],
		];

		yield 'SELECT * FROM (SELECT * FROM analyser_referenced_symbol_test) t' => [
			'query' => 'SELECT * FROM (SELECT * FROM analyser_referenced_symbol_test) t',
			'expectedReferencedSymbols' => [
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];
	}

	/** @return iterable<string, array<mixed>> */
	public static function provideTestInvalidData(): iterable
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query('
			CREATE OR REPLACE TABLE analyser_referenced_symbol_test_invalid (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(255) NULL
			);
		');
		$db->query("INSERT INTO analyser_referenced_symbol_test_invalid (id, name) VALUES (1, 'aa'), (2, NULL)");
		$table = new Table('analyser_referenced_symbol_test_invalid', TestCaseHelper::getDefaultDbName());

		yield 'invalid query' => [
			'query' => 'asdasasd',
			'expectedReferencedSymbols' => null,
		];

		$missingTable = new Table('missing_table', TestCaseHelper::getDefaultDbName());
		$simpleTableReferences = [
			'SELECT 1 FROM missing_table',
			'INSERT INTO missing_table VALUES ()',
			'REPLACE INTO missing_table VALUES ()',
			'TRUNCATE TABLE missing_table',
			'DELETE FROM missing_table',
		];

		foreach ($simpleTableReferences as $query) {
			yield $query => [
				'query' => $query,
				'expectedReferencedSymbols' => [$missingTable],
			];
		}

		$missingDbTable = new Table('missing_table', 'missing_db');
		$simpleDbTableReferences = [
			'SELECT 1 FROM missing_db.missing_table',
			'INSERT INTO missing_db.missing_table VALUES ()',
			'REPLACE INTO missing_db.missing_table VALUES ()',
			'TRUNCATE TABLE missing_db.missing_table',
			'DELETE FROM missing_db.missing_table',
		];

		foreach ($simpleDbTableReferences as $query) {
			yield $query => [
				'query' => $query,
				'expectedReferencedSymbols' => [$missingDbTable],
			];
		}

		yield 'detect valid table references even if invalid tables are referenced' => [
			'query' => 'SELECT * FROM analyser_referenced_symbol_test_invalid, missing_table',
			'expectedReferencedSymbols' => [
				$table,
				$missingTable,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		yield 'detect valid table references even if invalid tables are referenced - flipped' => [
			'query' => 'SELECT * FROM missing_table, analyser_referenced_symbol_test_invalid',
			'expectedReferencedSymbols' => [
				$missingTable,
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		yield 'detect valid column references even if invalid columns are referenced' => [
			'query' => 'SELECT aaa, name FROM analyser_referenced_symbol_test_invalid',
			'expectedReferencedSymbols' => [
				$table,
				// TODO: detect invalid column usage as well. It's more complicate because we'd have to record it for
				// all possible candidate tables.
				new TableColumn($table, 'name'),
			],
		];
	}

	/** @param ?array<ReferencedSymbol> $expectedReferencedSymbols */
	#[DataProvider('provideTestValidData')]
	#[DataProvider('provideTestInvalidData')]
	public function test(string $query, ?array $expectedReferencedSymbols): void
	{
		$analyser = TestCaseHelper::createAnalyser();
		$result = $analyser->analyzeQuery($query);
		$this->assertEqualsCanonicalizing($expectedReferencedSymbols, $result->referencedSymbols);
	}
}
