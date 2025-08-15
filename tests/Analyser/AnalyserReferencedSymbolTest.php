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
	public static function provideTestData(): iterable
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE analyser_referenced_symbol_test (
				id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(255) NULL
			);
		");
		$db->query("INSERT INTO analyser_referenced_symbol_test (id, name) VALUES (1, 'aa'), (2, NULL)");

		yield 'invalid query' => [
			'query' => 'asdasasd',
			'expected symbols' => null,
		];

		yield 'SELECT 1' => [
			'query' => 'SELECT 1',
			'expected symbols' => [],
		];

		$table = new Table('analyser_referenced_symbol_test', TestCaseHelper::getDefaultDbName());

		yield 'SELECT 1 FROM analyser_referenced_symbol_test' => [
			'query' => 'SELECT 1 FROM analyser_referenced_symbol_test',
			'expected symbols' => [$table],
		];

		yield 'SELECT id FROM analyser_referenced_symbol_test' => [
			'query' => 'SELECT id FROM analyser_referenced_symbol_test',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'SELECT * FROM analyser_referenced_symbol_test' => [
			'query' => 'SELECT * FROM analyser_referenced_symbol_test',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		yield 'SELECT * FROM CTE' => [
			'query' => 'WITH t AS (SELECT 1) SELECT * FROM t',
			'expected symbols' => [],
		];

		yield 'reference table twice' => [
			'query' => 'SELECT 1 FROM analyser_referenced_symbol_test, analyser_referenced_symbol_test',
			'expected symbols' => [$table],
		];

		yield 'reference column twice' => [
			'query' => 'SELECT id, id FROM analyser_referenced_symbol_test',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - field list' => [
			'query' => 'SELECT (SELECT id) FROM analyser_referenced_symbol_test',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - field list, semi-ambiguous' => [
			'query' => 'SELECT 1 id, (SELECT id) FROM analyser_referenced_symbol_test',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - WHERE ' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test WHERE (SELECT id) = 1',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from table - GROUP BY - ambiguous' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test GROUP BY id = 1',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - GROUP BY' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test GROUP BY (SELECT id) = 1',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from field list - HAVING' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test HAVING id = 1',
			'expected symbols' => [$table],
		];

		yield 'reference column from parent query - HAVING' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test HAVING (SELECT id) = 1',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference field from parent query - HAVING (SELECT WHERE)' => [
			'query' => 'SELECT "aa" id FROM analyser_referenced_symbol_test HAVING (SELECT 1 WHERE id = "aa") = 1',
			'expected symbols' => [
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
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
			],
		];

		yield 'reference field from parent query - HAVING (SELECT HAVING)' => [
			'query' => 'SELECT "aa" id FROM analyser_referenced_symbol_test HAVING (SELECT 1 HAVING id = "aa") = 1',
			'expected symbols' => [$table],
		];

		yield 'SELECT * FROM (SELECT * FROM analyser_referenced_symbol_test) t' => [
			'query' => 'SELECT * FROM (SELECT * FROM analyser_referenced_symbol_test) t',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		yield 'detect valid table references even if invalid tables are referenced' => [
			'query' => 'SELECT * FROM analyser_referenced_symbol_test, missing_table',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		yield 'detect valid table references even if invalid tables are referenced - flipped' => [
			'query' => 'SELECT * FROM missing_table, analyser_referenced_symbol_test',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		yield 'detect valid column references even if invalid columns are referenced' => [
			'query' => 'SELECT aaa, name FROM analyser_referenced_symbol_test',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'name'),
			],
		];
	}

	/** @param ?array<ReferencedSymbol> $expectedReferencedSymbols */
	#[DataProvider('provideTestData')]
	public function testValid(string $query, ?array $expectedReferencedSymbols): void
	{
		$analyser = TestCaseHelper::createAnalyser();
		$result = $analyser->analyzeQuery($query);
		$this->assertEqualsCanonicalizing($expectedReferencedSymbols, $result->referencedSymbols);
	}
}
