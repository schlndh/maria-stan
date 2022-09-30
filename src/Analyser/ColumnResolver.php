<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\Exception\DuplicateFieldNameException;
use MariaStan\Analyser\Exception\NotUniqueTableAliasException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Schema;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function reset;

// TODO: This code is completely brute-forced to try to match MariaDB's behavior. Try to find the logic behind it and
// rewrite it to make more sense.
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

	/** @var array<string, Schema\Table> CTE name => schema */
	private array $cteSchemas = [];

	/** @var array<string, array<string, QueryResultField>> subquery alias => name => field */
	private array $subquerySchemas = [];

	/** @var array<string, true> table => true */
	private array $tablesWithoutAliasMap = [];

	/** @var array<string, QueryResultField> name => field */
	private array $fieldList = [];
	private ColumnResolverFieldBehaviorEnum $fieldListBehavior = ColumnResolverFieldBehaviorEnum::FIELD_LIST;

	public function __construct(
		private readonly MariaDbOnlineDbReflection $dbReflection,
		private readonly ?self $parent = null,
	) {
	}

	/** @throws DbReflectionException | AnalyserException */
	public function registerTable(string $table, ?string $alias = null): void
	{
		$this->tableNamesInOrder[] = [$table, $alias];

		if ($alias !== null) {
			if (isset($this->tablesByAlias[$alias])) {
				throw new NotUniqueTableAliasException(
					AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage($alias),
				);
			}

			$this->tablesByAlias[$alias] = $table;
		} else {
			if (isset($this->tablesWithoutAliasMap[$table])) {
				throw new NotUniqueTableAliasException(
					AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage($table),
				);
			}

			$this->tablesWithoutAliasMap[$table] = true;
		}

		$schema = $this->tableSchemas[$table] ??= $this->findCteSchema($table)
			?? $this->dbReflection->findTableSchema($table);
		$this->registerColumnsForSchema($alias ?? $table, $schema->columns);
	}

	/**
	 * @param non-empty-array<QueryResultField> $fields
	 * @throws AnalyserException
	 */
	public function registerCommonTableExpression(array $fields, string $name): void
	{
		if (isset($this->cteSchemas[$name])) {
			throw new NotUniqueTableAliasException(
				AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage($name),
			);
		}

		$columns = $this->getColumnsFromFields($fields);
		$this->cteSchemas[$name] = new Schema\Table($name, $columns);
	}

	/** @param array<Schema\Column> $columns */
	private function registerColumnsForSchema(string $schemaName, array $columns): void
	{
		foreach ($columns as $column) {
			$this->columnSchemasByName[$column->name][] = [$schemaName, $column];
		}
	}

	/**
	 * @param array<QueryResultField> $fields
	 * @throws AnalyserException
	 */
	public function registerSubquery(array $fields, string $alias): void
	{
		// Subquery can have the same alias as a normal table, so we don't have to check it.
		if (isset($this->subquerySchemas[$alias])) {
			throw new NotUniqueTableAliasException(
				AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage($alias),
			);
		}

		$this->tableNamesInOrder[] = [null, $alias];
		$columns = $this->getColumnsFromFields($fields);

		foreach ($fields as $field) {
			$this->subquerySchemas[$alias][$field->name] = $field;
		}

		$this->registerColumnsForSchema($alias, $columns);
	}

	/**
	 * @param array<QueryResultField> $fields
	 * @return array<Schema\Column>
	 * @throws AnalyserException
	 */
	private function getColumnsFromFields(array $fields): array
	{
		$uniqueFieldNameMap = [];
		$columns = [];

		foreach ($fields as $field) {
			if (isset($uniqueFieldNameMap[$field->name])) {
				throw new DuplicateFieldNameException(
					AnalyserErrorMessageBuilder::createDuplicateColumnName($field->name),
				);
			}

			$uniqueFieldNameMap[$field->name] = true;
			$columns[] = new Schema\Column($field->name, $field->type, $field->isNullable);
		}

		return $columns;
	}

	public function registerOuterJoinedTable(string $table): void
	{
		$this->outerJoinedTableMap[$table] = true;
	}

	/** @param array<QueryResultField> $fields */
	public function registerFieldList(array $fields): void
	{
		$this->fieldList = [];

		foreach ($fields as $field) {
			$this->registerField($field);
		}
	}

	public function registerField(QueryResultField $field): void
	{
		// TODO: how to handle duplicate names? It seems that for ORDER BY/HAVING the first column with given
		// name has priority. However, if there is an alias then it trumps columns without alias.
		// SELECT id, -id id, 2*id id FROM analyser_test ORDER BY id;
		$this->fieldList[$field->name] ??= $field;
	}

	public function setFieldListBehavior(ColumnResolverFieldBehaviorEnum $shouldPreferFieldList): void
	{
		$this->fieldListBehavior = $shouldPreferFieldList;
	}

	/** @throws AnalyserException */
	public function resolveColumn(string $column, ?string $table): QueryResultField
	{
		if (
			$table === null
			&& $this->fieldListBehavior === ColumnResolverFieldBehaviorEnum::HAVING
			&& isset($this->fieldList[$column])
		) {
			return $this->fieldList[$column];
		}

		$candidateTables = $this->columnSchemasByName[$column] ?? [];

		if ($table !== null) {
			$candidateTables = array_filter(
				$candidateTables,
				static fn (array $t) => $t[0] === $table,
			);
		}

		switch (count($candidateTables)) {
			case 0:
				$resolvedParentColumn = null;

				try {
					$resolvedParentColumn = $this->parent?->resolveColumn($column, $table);
				} catch (AnalyserException) {
				}

				// TODO: add test to make sure that the prioritization is the same as in the database.
				// E.g. SELECT *, (SELECT id*2 id GROUP BY id%2) FROM analyser_test;
				return $resolvedParentColumn
					?? ($table === null ? $this->parent?->fieldList[$column] ?? null : null)
					?? (
						$table === null && $this->fieldListBehavior !== ColumnResolverFieldBehaviorEnum::FIELD_LIST
							? $this->fieldList[$column] ?? null
							: null
					)
					?? throw new AnalyserException(
						AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage($column, $table),
					);
			case 1:
				[$alias, $columnSchema] = reset($candidateTables);
				break;
			default:
				throw new AnalyserException(
					AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage($column, $table),
				);
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

	private function findCteSchema(string $name): ?Schema\Table
	{
		return $this->cteSchemas[$name] ?? $this->parent?->findCteSchema($name);
	}
}
