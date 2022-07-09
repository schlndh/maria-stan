<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\DatabaseTestCase;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;

use function array_keys;

use const MYSQLI_ASSOC;

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
	}

	/**
	 * @param array<QueryResultField> $expectedFields
	 * @dataProvider provideTestData
	 */
	public function test(string $query, array $expectedFields, Schema $expectedSchema): void
	{
		$db = $this->getDefaultSharedConnection();
		$parser = new MariaDbParser();
		$reflection = new MariaDbOnlineDbReflection($db);
		$analyser = new Analyser($parser, $reflection);
		$result = $analyser->analyzeQuery($query);
		$this->assertCount(0, $result->errors);
		$this->assertSame(array_keys($expectedFields), array_keys($result->resultFields));
		$this->assertEquals($expectedFields, $result->resultFields);
		$schemaProcessor = new Processor();
		$stmt = $db->query($query);
		$expectedFieldKeys = $this->getExpectedFieldKeys($expectedFields);

		foreach ($stmt->fetch_all(MYSQLI_ASSOC) as $row) {
			$this->assertSame($expectedFieldKeys, array_keys($row));
			$schemaProcessor->process($expectedSchema, $row);
		}
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
}
