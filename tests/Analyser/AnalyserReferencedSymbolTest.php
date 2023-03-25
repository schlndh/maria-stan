<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\ReferencedSymbol\ReferencedSymbol;
use MariaStan\Analyser\ReferencedSymbol\Table;
use MariaStan\Analyser\ReferencedSymbol\TableColumn;
use MariaStan\DbReflection\InformationSchemaParser;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\TestCaseHelper;
use PHPUnit\Framework\TestCase;

class AnalyserReferencedSymbolTest extends TestCase
{
	/** @return iterable<string, array<mixed>> */
	public function provideTestData(): iterable
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

		$table = new Table('analyser_referenced_symbol_test');

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

		yield 'reference column from field list - GROUP BY' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test GROUP BY id = 1',
			'expected symbols' => [
				$table,
				// TODO: fix this
				//new TableColumn($table, 'id'),
			],
		];

		yield 'reference column from parent query - GROUP BY' => [
			'query' => 'SELECT 1 id FROM analyser_referenced_symbol_test GROUP BY (SELECT id) = 1',
			'expected symbols' => [
				$table,
				// TODO: fix this
				//new TableColumn($table, 'id'),
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
				// TODO: fix this
				//new TableColumn($table, 'id'),
			],
		];

		yield 'SELECT * FROM (SELECT * FROM analyser_referenced_symbol_test) t' => [
			'query' => 'SELECT * FROM (SELECT * FROM analyser_referenced_symbol_test) t',
			'expected symbols' => [
				$table,
				new TableColumn($table, 'id'),
				new TableColumn($table, 'name'),
			],
		];

		yield 'detect valid symbols even if invalid symbols are references' => [
			'query' => 'SELECT * FROM analyser_referenced_symbol_test, missing_table',
			'expected symbols' => [
				$table,
				// TODO: fix this
				//new TableColumn($table, 'id'),
				//new TableColumn($table, 'name'),
			],
		];
	}

	/**
	 * @dataProvider provideTestData
	 * @param ?array<ReferencedSymbol> $expectedReferencedSymbols
	 */
	public function testValid(string $query, ?array $expectedReferencedSymbols): void
	{
		$analyser = $this->createAnalyser();
		$result = $analyser->analyzeQuery($query);
		$this->assertEqualsCanonicalizing($expectedReferencedSymbols, $result->referencedSymbols);
	}

	private function createAnalyser(): Analyser
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$functionInfoRegistry = TestCaseHelper::createFunctionInfoRegistry();
		$parser = new MariaDbParser($functionInfoRegistry);
		$informationSchemaParser = new InformationSchemaParser($parser);
		$reflection = new MariaDbOnlineDbReflection($db, $informationSchemaParser);

		return new Analyser($parser, $reflection, $functionInfoRegistry);
	}
}
