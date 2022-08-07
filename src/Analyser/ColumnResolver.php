<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Schema;

use function array_column;
use function array_map;
use function array_search;
use function array_unique;
use function count;
use function key;
use function reset;

final class ColumnResolver
{
	/** @var array<string, array<string, Schema\Column>> $columnSchemasByName column name => table name => schema */
	private array $columnSchemasByName = [];

	/** @var array<string, string> $tablesByAlias alias => table name */
	private array $tablesByAlias = [];

	/** @var array<string, bool> name => true */
	private array $outerJoinedTableMap = [];

	/** @var array<array{string, ?string}> [[table, alias]]*/
	private array $tableNamesInOrder = [];

	/** @var array<string, Schema\Table> name => schema */
	private array $tableSchemas = [];

	public function registerTable(string $table, ?string $alias = null): void
	{
		$this->tableNamesInOrder[] = [$table, $alias];

		if ($alias !== null) {
			// TODO: check unique alias
			$this->tablesByAlias[$alias] = $table;
		}
	}

	public function registerOuterJoinedTable(string $table): void
	{
		$this->outerJoinedTableMap[$table] = true;
	}

	/** @throws DbReflectionException */
	public function fetchSchemas(MariaDbOnlineDbReflection $dbReflection): void
	{
		foreach (array_unique(array_column($this->tableNamesInOrder, 0)) as $table) {
			if (isset($this->tablesByAlias[$table])) {
				$table = $this->tablesByAlias[$table];
			}

			if (isset($this->tableSchemas[$table])) {
				continue;
			}

			$this->tableSchemas[$table] = $dbReflection->findTableSchema($table);
		}

		$this->columnSchemasByName = [];

		foreach ($this->tableSchemas as $tableSchema) {
			foreach ($tableSchema->columns as $column) {
				$this->columnSchemasByName[$column->name][$tableSchema->name] = $column;
			}
		}
	}

	/** @throws AnalyserException */
	public function resolveColumn(string $column, ?string $table): QueryResultField
	{
		$candidateTables = $this->columnSchemasByName[$column] ?? [];

		if ($table !== null) {
			$columnSchema = $candidateTables[$table]
				?? $candidateTables[$this->tablesByAlias[$table] ?? null]
				?? null;
			$tableName = $table;

			if ($columnSchema === null) {
				throw new AnalyserException("Unknown column {$table}.{$column}");
			}
		} else {
			switch (count($candidateTables)) {
				case 0:
					throw new AnalyserException("Unknown column {$column}");
				case 1:
					$columnSchema = reset($candidateTables);
					$tableName = key($candidateTables);
					$alias = array_search($tableName, $this->tablesByAlias, true);

					if ($alias !== false) {
						$tableName = $alias;
					}

					break;
				default:
					throw new AnalyserException("Ambiguous column {$column}");
			}
		}

		$isOuterTable = $tableName !== null && ($this->outerJoinedTableMap[$tableName] ?? false);

		return new QueryResultField($column, $columnSchema->type, $columnSchema->isNullable || $isOuterTable);
	}

	/**
	 * @return array<QueryResultField>
	 * @throws AnalyserException
	 */
	public function resolveAllColumns(?string $table): array
	{
		$fields = [];
		$tableNames = $table !== null
			? [$table]
			// alias ?? table
			: array_map(static fn (array $t) => $t[1] ?? $t[0], $this->tableNamesInOrder);

		foreach ($tableNames as $tableName) {
			$isOuterTable = $this->outerJoinedTableMap[$tableName] ?? false;
			$normalizedTableName = $this->tablesByAlias[$tableName] ?? $tableName;
			$tableSchema = $this->tableSchemas[$normalizedTableName] ?? null;

			// TODO: error if schema is not found
			foreach ($tableSchema?->columns ?? [] as $column) {
				$fields[] = new QueryResultField($column->name, $column->type, $column->isNullable || $isOuterTable);
			}
		}

		return $fields;
	}
}
