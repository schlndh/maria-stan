<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\Exception\DuplicateFieldNameException;
use MariaStan\Analyser\Exception\NotUniqueTableAliasException;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\UsingJoinCondition;
use MariaStan\DbReflection\DbReflection;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Schema;

use function array_filter;
use function array_intersect_key;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function in_array;
use function reset;

// TODO: This code is completely brute-forced to try to match MariaDB's behavior. Try to find the logic behind it and
// rewrite it to make more sense.
final class ColumnResolver
{
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

	/** @var array<string, array<array{QueryResultField, ?bool}>> name => [[field, is column]] */
	private array $fieldList = [];

	/** @var array<array{column: string, table: string}> */
	private array $allColumns = [];

	private ColumnResolverFieldBehaviorEnum $fieldListBehavior = ColumnResolverFieldBehaviorEnum::FIELD_LIST;

	public function __construct(private readonly DbReflection $dbReflection, private readonly ?self $parent = null)
	{
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
		$columnNames = array_map(static fn (Schema\Column $c) => $c->name, $schema->columns);
		$this->registerColumnsForSchema($alias ?? $table, $columnNames);
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

	/** @param array<string> $columnNames */
	private function registerColumnsForSchema(string $schemaName, array $columnNames): void
	{
		foreach ($columnNames as $column) {
			$this->allColumns[] = ['column' => $column, 'table' => $schemaName];
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
		$columnNames = [];
		$uniqueFieldNameMap = [];

		foreach ($fields as $field) {
			if (isset($uniqueFieldNameMap[$field->name])) {
				throw new DuplicateFieldNameException(
					AnalyserErrorMessageBuilder::createDuplicateColumnName($field->name),
				);
			}

			$field = new QueryResultField(
				$field->name,
				new ExprTypeResult(
					$field->exprType->type,
					$field->exprType->isNullable,
					new SubqueryColumnInfo($field->name, $alias),
				),
			);
			$uniqueFieldNameMap[$field->name] = true;
			$columnNames[] = $field->name;
			$this->subquerySchemas[$alias][$field->name] = $field;
		}

		$this->registerColumnsForSchema($alias, $columnNames);
	}

	/**
	 * @param array<QueryResultField> $fields
	 * @return array<string, Schema\Column> name => column
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
			$columns[$field->name] = new Schema\Column(
				$field->name,
				$field->exprType->type,
				$field->exprType->isNullable,
			);
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
			$this->registerField($field, null);
		}
	}

	public function registerField(QueryResultField $field, ?bool $isColumn): void
	{
		// TODO: how to handle duplicate names? It seems that for ORDER BY/HAVING the first column with given
		// name has priority. However, if there is an alias then it trumps columns without alias.
		// SELECT id, -id id, 2*id id FROM analyser_test ORDER BY id;
		$this->fieldList[$field->name][] = [$field, $isColumn];
	}

	public function setFieldListBehavior(ColumnResolverFieldBehaviorEnum $shouldPreferFieldList): void
	{
		$this->fieldListBehavior = $shouldPreferFieldList;
	}

	/** @throws AnalyserException */
	public function resolveColumn(string $column, ?string $table): ExprTypeResult
	{
		if (
			$table === null
			&& $this->fieldListBehavior === ColumnResolverFieldBehaviorEnum::HAVING
			&& isset($this->fieldList[$column])
		) {
			return $this->fieldList[$column][0][0]->exprType;
		}

		if ($table === null) {
			$candidateFields = $this->fieldList[$column] ?? [];

			if ($this->fieldListBehavior === ColumnResolverFieldBehaviorEnum::GROUP_BY && count($candidateFields) > 0) {
				$columnCount = 0;
				$firstExpressionField = null;
				$firstField = null;

				/** @var ?bool $isColumn */
				foreach ($candidateFields as [$field, $isColumn]) {
					assert($field instanceof QueryResultField);
					$firstField ??= $field;

					// For now we don't have to worry about it. It's only null in UNION/... which can't have GROUP BY.
					if ($isColumn === null) {
						continue;
					}

					if ($isColumn) {
						$columnCount++;
					} else {
						$firstExpressionField ??= $field;
					}
				}

				if ($columnCount > 1) {
					throw new AnalyserException(
						AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage($column, $table),
					);
				}

				// If there are multiple expressions with the same alias the first one seems to be used.
				return ($firstExpressionField ?? $firstField)->exprType;
			}
		}

		$candidateTables = array_filter(
			$this->allColumns,
			$table === null
				? static fn (array $t) => $t['column'] === $column
				: static fn (array $t) => $t['column'] === $column && $t['table'] === $table,
		);
		$columnInfo = null;

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
					?? ($table === null ? $this->parent?->findUniqueItemInFieldList($column)?->exprType : null)
					?? (
						$table === null && $this->fieldListBehavior !== ColumnResolverFieldBehaviorEnum::FIELD_LIST
							? $this->findUniqueItemInFieldList($column)?->exprType
							: null
					)
					?? throw new AnalyserException(
						AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage($column, $table),
					);
			case 1:
				$alias = reset($candidateTables)['table'];
				$tableName = $this->tablesByAlias[$alias] ?? $alias;

				if (isset($this->tableSchemas[$tableName])) {
					$columnSchema = $this->tableSchemas[$tableName]->columns[$column];
					$columnInfo = isset($this->cteSchemas[$tableName])
						? new SubqueryColumnInfo($column, $alias)
						: new TableColumnInfo($column, $tableName, $alias);
				} elseif (isset($this->subquerySchemas[$alias])) {
					$columnField = $this->subquerySchemas[$alias][$column];
					$columnSchema = new Schema\Column(
						$columnField->name,
						$columnField->exprType->type,
						$columnField->exprType->isNullable,
					);
					$columnInfo = new SubqueryColumnInfo($column, $alias);
				} else {
					throw new AnalyserException("Unhandled edge-case: can't find schema for {$tableName}.{$column}");
				}

				break;
			default:
				throw new AnalyserException(
					AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage($column, $table),
				);
		}

		$isOuterTable = $this->outerJoinedTableMap[$alias] ?? false;

		return new ExprTypeResult($columnSchema->type, $columnSchema->isNullable || $isOuterTable, $columnInfo);
	}

	private function findUniqueItemInFieldList(string $name): ?QueryResultField
	{
		$candidates = $this->fieldList[$name] ?? [];
		$count = count($candidates);

		if ($count === 0 || $count > 1) {
			return null;
		}

		return $candidates[0][0];
	}

	/**
	 * @return array<QueryResultField>
	 * @throws AnalyserException
	 */
	public function resolveAllColumns(?string $table): array
	{
		$fields = [];

		if ($table !== null) {
			$isOuterTable = $this->outerJoinedTableMap[$table] ?? false;
			$normalizedTableName = $this->tablesByAlias[$table] ?? $table;
			$tableSchema = $this->tableSchemas[$normalizedTableName] ?? null;

			if ($tableSchema !== null) {
				foreach ($tableSchema->columns ?? [] as $column) {
					$fields[] = new QueryResultField(
						$column->name,
						new ExprTypeResult(
							$column->type,
							$column->isNullable || $isOuterTable,
							isset($this->cteSchemas[$normalizedTableName])
								? new SubqueryColumnInfo($column->name, $table)
								: new TableColumnInfo($column->name, $normalizedTableName, $table),
						),
					);
				}
			} elseif (isset($this->subquerySchemas[$table])) {
				$fields = array_merge($fields, array_values($this->subquerySchemas[$table]));
			} else {
				// TODO: error if schema is not found
			}
		} else {
			foreach ($this->allColumns as ['column' => $column, 'table' => $table]) {
				$isOuterTable = $this->outerJoinedTableMap[$table] ?? false;
				$normalizedTableName = $this->tablesByAlias[$table] ?? $table;
				$tableSchema = $this->tableSchemas[$normalizedTableName] ?? null;

				if ($tableSchema !== null) {
					$columnSchema = $tableSchema->columns[$column] ?? null;

					// This would have already been reported previously, so let's ignore it.
					if ($columnSchema === null) {
						continue;
					}

					$fields[] = new QueryResultField(
						$columnSchema->name,
						new ExprTypeResult(
							$columnSchema->type,
							$columnSchema->isNullable || $isOuterTable,
							isset($this->cteSchemas[$normalizedTableName])
								? new SubqueryColumnInfo($columnSchema->name, $table)
								: new TableColumnInfo($columnSchema->name, $normalizedTableName, $table),
						),
					);
				} elseif (isset($this->subquerySchemas[$table])) {
					$f = $this->subquerySchemas[$table][$column] ?? null;

					// This would have already been reported previously, so let's ignore it.
					if ($f === null) {
						continue;
					}

					$fields[] = $f;
				} else {
					// TODO: error if schema is not found
				}
			}
		}

		return $fields;
	}

	private function findCteSchema(string $name): ?Schema\Table
	{
		return $this->cteSchemas[$name] ?? $this->parent?->findCteSchema($name);
	}

	/** @throws AnalyserException */
	public function mergeAfterJoin(ColumnResolver $other, Join $join): void
	{
		$duplicateAliases = array_intersect_key($this->tablesByAlias, $other->tablesByAlias);

		if (count($duplicateAliases) > 0) {
			throw new AnalyserException(
				AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage(array_key_first($duplicateAliases)),
			);
		}

		$this->tablesByAlias = array_merge($this->tablesByAlias, $other->tablesByAlias);
		$this->outerJoinedTableMap = array_merge($this->outerJoinedTableMap, $other->outerJoinedTableMap);
		$this->tableNamesInOrder = array_merge($this->tableNamesInOrder, $other->tableNamesInOrder);
		$this->tableSchemas = array_merge($this->tableSchemas, $other->tableSchemas);

		$duplicateSubqueries = array_intersect_key($this->subquerySchemas, $other->subquerySchemas);

		if (count($duplicateSubqueries) > 0) {
			throw new AnalyserException(
				AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage(
					array_key_first($duplicateSubqueries),
				),
			);
		}

		$this->subquerySchemas = array_merge($this->subquerySchemas, $other->subquerySchemas);

		$duplicateTables = array_intersect_key($this->tablesWithoutAliasMap, $other->tablesWithoutAliasMap);

		if (count($duplicateTables) > 0) {
			throw new AnalyserException(
				AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage(
					array_key_first($duplicateTables),
				),
			);
		}

		$this->tablesWithoutAliasMap = array_merge($this->tablesWithoutAliasMap, $other->tablesWithoutAliasMap);

		if ($join->joinCondition instanceof UsingJoinCondition) {
			$newAllColumns = [];
			$usingColnames = $join->joinCondition->columnNames;

			foreach ($usingColnames as $colname) {
				$this->resolveUsingColumn($colname);
				$other->resolveUsingColumn($colname);
			}

			$primaryColumns = $join->joinType !== JoinTypeEnum::RIGHT_OUTER_JOIN
				? $this->allColumns
				: $other->allColumns;

			// USING eliminates redundant columns and changes column ordering.
			// https://dev.mysql.com/doc/refman/8.0/en/join.html
			foreach ($primaryColumns as $tableCol) {
				if (! in_array($tableCol['column'], $usingColnames, true)) {
					continue;
				}

				$newAllColumns[] = $tableCol;
			}

			foreach (array_merge($this->allColumns, $other->allColumns) as $tableCol) {
				if (in_array($tableCol['column'], $usingColnames, true)) {
					continue;
				}

				$newAllColumns[] = $tableCol;
			}

			$this->allColumns = $newAllColumns;
		} else {
			$this->allColumns = array_merge($this->allColumns, $other->allColumns);
		}
	}

	/** @throws AnalyserException */
	public function resolveUsingColumn(string $column): void
	{
		$candidateTables = array_filter(
			$this->allColumns,
			static fn (array $t) => $t['column'] === $column,
		);

		switch (count($candidateTables)) {
			case 0:
				throw new AnalyserException(AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage($column));
			case 1:
				return;
			default:
				throw new AnalyserException(AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage($column));
		}
	}

	public function findTableSchema(string $tableName): ?Schema\Table
	{
		return $this->tableSchemas[$tableName] ?? null;
	}

	/** @return array<string> */
	public function getCollidingSubqueryAndTableAliases(): array
	{
		return array_keys(
			array_intersect_key(array_merge($this->tablesByAlias, $this->tableSchemas), $this->subquerySchemas),
		);
	}

	public function hasTableForDelete(string $table): bool
	{
		return isset($this->tablesByAlias[$table])
			|| (isset($this->tableSchemas[$table]) && ! in_array($table, $this->tablesByAlias, true));
	}
}
