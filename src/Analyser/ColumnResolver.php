<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Schema;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function reset;

final class ColumnResolver
{
	/**
	 * @var array<string, array<array{string, Schema\Column}>> $columnSchemasByName
	 * 	column name => [[alias/table name, schema]]
	 */
	private array $columnSchemasByName = [];

	/** @var array<string, string> $tablesByAlias alias => table name */
	private array $tablesByAlias = [];

	/** @var array<string, bool> name => true */
	private array $outerJoinedTableMap = [];

	/** @var array<array{string, ?string}|array{null, string}> [[table, alias]] (for subquery table is null) */
	private array $tableNamesInOrder = [];

	/** @var array<string, Schema\Table> name => schema */
	private array $tableSchemas = [];

	/** @var array<string, array<string, QueryResultField>> subquery alias => name => field */
	private array $subquerySchemas = [];

	public function __construct(
		private readonly MariaDbOnlineDbReflection $dbReflection,
		private readonly ?self $parent = null,
	) {
	}

	/** @throws DbReflectionException */
	public function registerTable(string $table, ?string $alias = null): void
	{
		$this->tableNamesInOrder[] = [$table, $alias];

		if ($alias !== null) {
			// TODO: check unique alias
			$this->tablesByAlias[$alias] = $table;
		}

		$schema = $this->tableSchemas[$table] ??= $this->dbReflection->findTableSchema($table);

		foreach ($schema->columns as $column) {
			$this->columnSchemasByName[$column->name][] = [$alias ?? $table, $column];
		}
	}

	/**
	 * @param array<QueryResultField> $fields
	 * @throws AnalyserException
	 */
	public function registerSubquery(array $fields, string $alias): void
	{
		// TODO: check also $tablesByAlias?
		if (isset($this->subquerySchemas[$alias])) {
			throw new AnalyserException("Not unique table/alias: '{$alias}'");
		}

		$this->tableNamesInOrder[] = [null, $alias];

		foreach ($fields as $field) {
			$this->subquerySchemas[$alias][$field->name] = $field;
			$fieldSchema = new Schema\Column($field->name, $field->type, $field->isNullable);
			$this->columnSchemasByName[$field->name][] = [$alias, $fieldSchema];
		}
	}

	public function registerOuterJoinedTable(string $table): void
	{
		$this->outerJoinedTableMap[$table] = true;
	}

	/** @throws AnalyserException */
	public function resolveColumn(string $column, ?string $table): QueryResultField
	{
		$candidateTables = $this->columnSchemasByName[$column] ?? [];

		if ($table !== null) {
			$candidateTables = array_filter(
				$candidateTables,
				static fn (array $t) => $t[0] === $table,
			);
		}

		switch (count($candidateTables)) {
			case 0:
				return $this->parent?->resolveColumn($column, $table)
					?? throw new AnalyserException("Unknown column {$this->formatColumnName($column, $table)}");
			case 1:
				[$alias, $columnSchema] = reset($candidateTables);
				break;
			default:
				throw new AnalyserException("Ambiguous column {$this->formatColumnName($column, $table)}");
		}

		$isOuterTable = $this->outerJoinedTableMap[$alias] ?? false;

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

			if ($tableSchema !== null) {
				foreach ($tableSchema->columns ?? [] as $column) {
					$fields[] = new QueryResultField(
						$column->name,
						$column->type,
						$column->isNullable || $isOuterTable,
					);
				}
			} elseif (isset($this->subquerySchemas[$tableName])) {
				$fields = array_merge($fields, array_values($this->subquerySchemas[$tableName]));
			} else {
				// TODO: error if schema is not found
			}
		}

		return $fields;
	}

	private function formatColumnName(string $column, ?string $table): string
	{
		return $table === null
			? $column
			: "{$table}.{$column}";
	}
}