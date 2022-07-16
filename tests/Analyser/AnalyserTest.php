<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\DatabaseTestCase;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;

use function array_keys;
use function array_map;
use function implode;

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

		// TODO: fix missing types: ~ is unsigned 64b int, so it's too large for PHP. -name is double.
		yield 'unary ops' => [
			'query' => "
				SELECT
				    -id, +id, !id, /*~id,*/
				    /*-name,*/ +name, !name/*, ~name*/
				FROM {$tableName}
			",
			'expected fields' => [
				new QueryResultField('-id', new IntType(), false),
				new QueryResultField('id', new IntType(), false),
				new QueryResultField('!id', new IntType(), false),
				//new QueryResultField('~id', new IntType(), false),
				//new QueryResultField('-name', new IntType(), true),
				new QueryResultField('name', new VarcharType(), true),
				new QueryResultField('!name', new IntType(), true),
				//new QueryResultField('~name', new IntType(), true),
			],
			'expected schema' => Expect::structure([
				'-id' => Expect::int(),
				'id' => Expect::int(),
				'!id' => Expect::int(),
				//'~id' => Expect::anyOf(Expect::int(), Expect::string()),
				//'-name' => Expect::anyOf(Expect::float(), Expect::null()),
				'name' => Expect::anyOf(Expect::string(), Expect::null()),
				'!name' => Expect::anyOf(Expect::int(), Expect::string(), Expect::null()),
				//'~name' => Expect::anyOf(Expect::int(), Expect::string(), Expect::null()),
			]),
		];

		yield from $this->provideDataTypeData();
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
		$this->assertCount(
			0,
			$result->errors,
			"Expected no errors. Got: "
				. implode("\n", array_map(static fn (AnalyserError $e) => $e->message, $result->errors)),
		);
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
