<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\Exception\DuplicateFieldNameException;
use MariaStan\Analyser\Exception\NotUniqueTableAliasException;
use MariaStan\Analyser\FullyQualifiedColumn\FieldListFullyQualifiedColumn;
use MariaStan\Analyser\FullyQualifiedColumn\FullyQualifiedColumn;
use MariaStan\Analyser\FullyQualifiedColumn\FullyQualifiedColumnTypeEnum;
use MariaStan\Analyser\FullyQualifiedColumn\ParentFullyQualifiedColumn;
use MariaStan\Analyser\FullyQualifiedColumn\TableFullyQualifiedColumn;
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

	/** @var array<string, array<string, bool>> table/subquery alias => column name => true */
	private array $groupByColumns = [];

	private ColumnResolverFieldBehaviorEnum $fieldListBehavior = ColumnResolverFieldBehaviorEnum::FIELD_LIST;
	private int $aggregateFunctionDepth = 0;
	private ?AnalyserKnowledgeBase $knowledgeBase = null;

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
					new ColumnInfo($field->name, $alias, $alias, ColumnInfoTableTypeEnum::SUBQUERY),
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

	public function registerGroupByColumn(ColumnInfo $column): void
	{
		$this->groupByColumns[$column->tableAlias][$column->name] = true;
	}

	public function setFieldListBehavior(ColumnResolverFieldBehaviorEnum $shouldPreferFieldList): void
	{
		$this->fieldListBehavior = $shouldPreferFieldList;
	}

	/** @throws AnalyserException */
	public function resolveColumn(
		string $column,
		?string $table,
		?AnalyserConditionTypeEnum $condition = null,
	): ExprTypeResult {
		$resolvedColumn = $this->resolveColumnName($column, $table);

		return $this->getTypeForFullyQualifiedColumn($resolvedColumn, $condition);
	}

	/** @throws AnalyserException */
	private function getTypeForFullyQualifiedColumn(
		FullyQualifiedColumn $column,
		?AnalyserConditionTypeEnum $condition = null,
	): ExprTypeResult {
		switch ($column::getColumnType()) {
			case FullyQualifiedColumnTypeEnum::FIELD_LIST:
				assert($column instanceof FieldListFullyQualifiedColumn);

				return $column->field->exprType;
			case FullyQualifiedColumnTypeEnum::PARENT:
				assert($column instanceof ParentFullyQualifiedColumn);
				assert($this->parent !== null);

				if ($this->aggregateFunctionDepth > 0) {
					$this->parent->enterAggregateFunction();
				}

				try {
					return $this->parent->getTypeForFullyQualifiedColumn($column->parentColumn, $condition);
				} finally {
					if ($this->aggregateFunctionDepth > 0) {
						$this->parent->exitAggregateFunction();
					}
				}
		}

		assert($column instanceof TableFullyQualifiedColumn);
		$columnInfo = $column->columnInfo;

		if (
			$this->fieldListBehavior === ColumnResolverFieldBehaviorEnum::HAVING
			&& $this->aggregateFunctionDepth === 0
			&& ! isset($this->groupByColumns[$columnInfo->tableAlias][$columnInfo->name])
		) {
			$isColumnUsedInFieldList = false;

			foreach ($this->fieldList as $fields) {
				foreach ($fields as [$field]) {
					assert($field instanceof QueryResultField);
					$fieldColumn = $field->exprType->column;

					if (
						$fieldColumn === null
						|| $fieldColumn->name !== $columnInfo->name
						|| $fieldColumn->tableAlias !== $columnInfo->tableAlias
					) {
						continue;
					}

					$isColumnUsedInFieldList = true;
					break 2;
				}
			}

			if (! $isColumnUsedInFieldList) {
				throw new AnalyserException(
					AnalyserErrorMessageBuilder::createInvalidHavingColumn($columnInfo->name),
				);
			}
		}

		$exprType = $this->findColumnExprType($columnInfo->tableAlias, $columnInfo->name)
			?? throw new AnalyserException("Unknown column {$columnInfo->tableAlias}.{$columnInfo->name}");
		$knowledgeBase = null;

		if ($condition === AnalyserConditionTypeEnum::NULL || $condition === AnalyserConditionTypeEnum::NOT_NULL) {
			$isNullCondition = $condition === AnalyserConditionTypeEnum::NULL;

			if ($exprType->type::getTypeEnum() === Schema\DbType\DbTypeEnum::NULL) {
				$knowledgeBase = AnalyserKnowledgeBase::createFixed($isNullCondition);
			} elseif (! $exprType->isNullable) {
				$knowledgeBase = AnalyserKnowledgeBase::createFixed(! $isNullCondition);
			} else {
				$knowledgeBase = AnalyserKnowledgeBase::createForSingleColumn($columnInfo, $isNullCondition);
			}
		} elseif ($condition !== null) {
			// Both TRUTHY and FALSY require the column to be non-nullable.
			$knowledgeBase = $exprType->type::getTypeEnum() === Schema\DbType\DbTypeEnum::NULL
				? AnalyserKnowledgeBase::createFixed(false)
				: AnalyserKnowledgeBase::createForSingleColumn($columnInfo, false);
		}

		return $knowledgeBase === null
			? $exprType
			: new ExprTypeResult($exprType->type, $exprType->isNullable, $exprType->column, $knowledgeBase);
	}

	/** @throws AnalyserException */
	private function resolveColumnName(string $column, ?string $table): FullyQualifiedColumn
	{
		if (
			$table === null
			&& in_array(
				$this->fieldListBehavior,
				[ColumnResolverFieldBehaviorEnum::HAVING, ColumnResolverFieldBehaviorEnum::ORDER_BY],
				true,
			)
			&& isset($this->fieldList[$column])
		) {
			return new FieldListFullyQualifiedColumn($this->fieldList[$column][0][0]);
		}

		$candidateTables = array_filter(
			$this->allColumns,
			$table === null
				? static fn (array $t) => $t['column'] === $column
				: static fn (array $t) => $t['column'] === $column && $t['table'] === $table,
		);

		if ($table === null) {
			$candidateFields = $this->fieldList[$column] ?? [];

			if ($this->fieldListBehavior === ColumnResolverFieldBehaviorEnum::GROUP_BY && count($candidateFields) > 0) {
				$firstExpressionField = null;
				$firstField = null;
				$columnMap = [];
				$isAmbiguousWarning = false;
				$candidateTable = null;

				if (count($candidateTables) === 1) {
					$candidateTable = reset($candidateTables)['table'];
				}

				/** @var ?bool $isColumn */
				foreach ($candidateFields as [$field, $isColumn]) {
					assert($field instanceof QueryResultField);
					$firstField ??= $field;

					// For now we don't have to worry about it. It's only null in UNION/... which can't have GROUP BY.
					if ($isColumn === null) {
						continue;
					}

					if ($isColumn) {
						assert($field->exprType->column !== null);
						$columnMap[$field->exprType->column->tableAlias][$field->exprType->column->name] = true;

						if (
							$candidateTable !== null
							&& (
								$field->exprType->column->tableAlias !== $candidateTable
								|| $field->exprType->column->name !== $column
							)
						) {
							$isAmbiguousWarning = true;
						}
					} else {
						$firstExpressionField ??= $field;

						if ($candidateTable !== null) {
							$isAmbiguousWarning = true;
						}
					}
				}

				$uniqueColumnCount = 0;

				foreach ($columnMap as $columns) {
					$uniqueColumnCount += count($columns);
				}

				if ($uniqueColumnCount > 1 || $isAmbiguousWarning) {
					throw new AnalyserException(
						AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage(
							$column,
							$table,
							$isAmbiguousWarning,
						),
					);
				}

				if ($candidateTable !== null) {
					return new TableFullyQualifiedColumn(
						$this->getColumnInfo($candidateTable, $column),
					);
				}

				// If there are multiple expressions with the same alias the first one seems to be used.
				return new FieldListFullyQualifiedColumn($firstExpressionField ?? $firstField);
			}
		}

		switch (count($candidateTables)) {
			case 0:
				$resolvedParentColumn = null;

				if ($this->aggregateFunctionDepth > 0) {
					$this->parent?->enterAggregateFunction();
				}

				try {
					$resolvedParentColumn = $this->parent?->resolveColumnName($column, $table);
				} catch (AnalyserException) {
				}

				if ($this->aggregateFunctionDepth > 0) {
					$this->parent?->exitAggregateFunction();
				}

				// TODO: add test to make sure that the prioritization is the same as in the database.
				// E.g. SELECT *, (SELECT id*2 id GROUP BY id%2) FROM analyser_test;
				if ($resolvedParentColumn !== null) {
					return new ParentFullyQualifiedColumn($resolvedParentColumn);
				}

				if ($table === null) {
					$parentField = $this->parent?->findUniqueItemInFieldList($column);

					if ($parentField !== null) {
						return new FieldListFullyQualifiedColumn($parentField);
					}

					if ($this->fieldListBehavior !== ColumnResolverFieldBehaviorEnum::FIELD_LIST) {
						$field = $this->findUniqueItemInFieldList($column);

						if ($field !== null) {
							return new FieldListFullyQualifiedColumn($field);
						}
					}
				}

				throw new AnalyserException(
					AnalyserErrorMessageBuilder::createUnknownColumnErrorMessage($column, $table),
				);
			case 1:
				return new TableFullyQualifiedColumn(
					$this->getColumnInfo(reset($candidateTables)['table'], $column),
				);
			default:
				throw new AnalyserException(
					AnalyserErrorMessageBuilder::createAmbiguousColumnErrorMessage($column, $table),
				);
		}
	}

	/** @throws AnalyserException */
	private function getColumnInfo(string $table, string $column): ColumnInfo
	{
		$tableName = $this->tablesByAlias[$table] ?? $table;

		if (isset($this->tableSchemas[$tableName])) {
			return new ColumnInfo(
				$column,
				$tableName,
				$table,
				isset($this->cteSchemas[$tableName])
					? ColumnInfoTableTypeEnum::SUBQUERY
					: ColumnInfoTableTypeEnum::TABLE,
			);
		}

		if (isset($this->subquerySchemas[$table])) {
			return new ColumnInfo($column, $tableName, $table, ColumnInfoTableTypeEnum::SUBQUERY);
		}

		throw new AnalyserException("Unhandled edge-case: can't find schema for {$tableName}.{$column}");
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
			$normalizedTableName = $this->tablesByAlias[$table] ?? $table;
			$tableSchema = $this->tableSchemas[$normalizedTableName] ?? null;

			if ($tableSchema !== null) {
				$columnNames = array_keys($tableSchema->columns);
			} elseif (isset($this->subquerySchemas[$table])) {
				$columnNames = array_keys($this->subquerySchemas[$table]);
			} else {
				// TODO: error if schema is not found
				return [];
			}

			foreach ($columnNames as $column) {
				$exprType = $this->findColumnExprType($table, $column);

				// This would have already been reported previously, so let's ignore it.
				if ($exprType === null) {
					continue;
				}

				$fields[] = new QueryResultField($column, $exprType);
			}
		} else {
			foreach ($this->allColumns as ['column' => $column, 'table' => $table]) {
				$exprType = $this->findColumnExprType($table, $column);

				// This would have already been reported previously, so let's ignore it.
				if ($exprType === null) {
					continue;
				}

				$fields[] = new QueryResultField($column, $exprType);
			}
		}

		return $fields;
	}

	private function findColumnExprType(string $table, string $column): ?ExprTypeResult
	{
		$isOuterTable = $this->outerJoinedTableMap[$table] ?? false;
		$normalizedTableName = $this->tablesByAlias[$table] ?? $table;
		$tableSchema = $this->tableSchemas[$normalizedTableName] ?? null;

		if ($tableSchema !== null) {
			$columnSchema = $tableSchema->columns[$column] ?? null;

			if ($columnSchema === null) {
				return null;
			}

			$nullability = $this->knowledgeBase->columnNullability[$table][$columnSchema->name] ?? null;
			$type = $columnSchema->type;
			$isNullable = $columnSchema->isNullable || $isOuterTable;

			if ($nullability === true) {
				$type = new Schema\DbType\NullType();
				$isNullable = true;
			} elseif ($nullability === false) {
				// TODO: what if $type is NullType?
				$isNullable = false;
			}

			return new ExprTypeResult(
				$type,
				$isNullable,
				new ColumnInfo(
					$columnSchema->name,
					$normalizedTableName,
					$table,
					isset($this->cteSchemas[$normalizedTableName])
						? ColumnInfoTableTypeEnum::SUBQUERY
						: ColumnInfoTableTypeEnum::TABLE,
				),
			);
		}

		if (isset($this->subquerySchemas[$table])) {
			$f = $this->subquerySchemas[$table][$column] ?? null;

			return $f?->exprType;
		}

		return null;
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
	private function resolveUsingColumn(string $column): void
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

	public function enterAggregateFunction(): void
	{
		$this->aggregateFunctionDepth++;
	}

	/** @throws AnalyserException */
	public function exitAggregateFunction(): void
	{
		if ($this->aggregateFunctionDepth === 0) {
			throw new AnalyserException('Invalid state: exiting aggregate function without entering it.');
		}

		$this->aggregateFunctionDepth--;
	}

	public function addKnowledge(?AnalyserKnowledgeBase $knowledgeBase): void
	{
		if ($knowledgeBase === null) {
			return;
		}

		if ($this->knowledgeBase === null) {
			$this->knowledgeBase = $knowledgeBase;

			return;
		}

		$this->knowledgeBase = $this->knowledgeBase->and($knowledgeBase);
	}
}
