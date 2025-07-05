<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\Exception\DuplicateFieldNameException;
use MariaStan\Analyser\Exception\NotUniqueTableAliasException;
use MariaStan\Analyser\Exception\ShouldNotHappenException;
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
	// This should be something that is not a valid DB name
	private const SUBQUERY_DB = "\0SUBQUERY\0";

	/** @var array<string, array<string, string>> $tablesByAlias alias => database w/ fallback => table name */
	private array $tablesByAlias = [];

	/** @var array<string, array<string, bool>> database w/ fallback => name => true */
	private array $outerJoinedTableMap = [];

	/** @var array<string, array<string, Schema\Table>> database => name => schema */
	private array $tableSchemas = [];

	/** @var array<string, Schema\Table> CTE name => schema */
	private array $cteSchemas = [];

	/** @var array<string, array<string, QueryResultField>> subquery alias => name => field */
	private array $subquerySchemas = [];

	/** @var array<string, array<array{QueryResultField, ?bool}>> name => [[field, is column]] */
	private array $fieldList = [];

	/** @var array<array{column: string, table: string, database: ?string}> */
	private array $allColumns = [];

	/**
	 * @var array<string, array<string, array<string, bool>>>
	 *     DB w/ fallback => table/subquery alias => column name => true
	 */
	private array $groupByColumns = [];

	private ?Schema\Table $insertReplaceTargetTable = null;

	private int $aggregateFunctionDepth = 0;
	private ?AnalyserKnowledgeBase $knowledgeBase = null;

	public function __construct(
		private readonly DbReflection $dbReflection,
		private readonly ?self $parent = null,
		private readonly bool $canReferenceGrandParent = false,
	) {
	}

	public function registerInsertReplaceTargetTable(Schema\Table $table, ?string $database): void
	{
		$database ??= $this->dbReflection->getDefaultDatabase();
		$this->insertReplaceTargetTable = $table;
		$this->registerColumnsForSchema($table->name, array_keys($table->columns), $database);
		$this->tableSchemas[$database][$table->name] = $table;
	}

	/** @throws DbReflectionException | AnalyserException */
	public function registerTable(
		string $table,
		?string $alias = null,
		?string $database = null,
	): ColumnInfoTableTypeEnum {
		$origDatabase = $database;
		$database ??= $this->dbReflection->getDefaultDatabase();
		$alias ??= $table;

		if (isset($this->tablesByAlias[$alias][$database])) {
			throw new NotUniqueTableAliasException($alias, $origDatabase);
		}

		$schema = $this->tableSchemas[$database][$table] ?? null;
		$schemaDatabase = $database;

		if ($schema === null) {
			// CTEs can't be used with DB prefix
			$schema = $origDatabase === null
				? $this->findCteSchema($table)
				: null;

			if ($schema !== null) {
				$type = ColumnInfoTableTypeEnum::SUBQUERY;
				$schemaDatabase = null;
			} else {
				$schema = $this->tableSchemas[$database][$table]
					= $this->dbReflection->findTableSchema($table, $origDatabase);
				$type = ColumnInfoTableTypeEnum::TABLE;
				$this->tableSchemas[$database][$table] ??= $schema;
			}
		} else {
			$type = $this->getTableType($alias, $origDatabase);
		}

		if ($type === ColumnInfoTableTypeEnum::TABLE) {
			$this->tablesByAlias[$alias][$database] = $table;
		} else {
			$this->tablesByAlias[$alias][self::SUBQUERY_DB] = $table;
		}

		$columnNames = array_map(static fn (Schema\Column $c) => $c->name, $schema->columns);
		$this->registerColumnsForSchema($alias, $columnNames, $schemaDatabase);

		return $type;
	}

	/**
	 * @param non-empty-array<QueryResultField> $fields
	 * @throws AnalyserException
	 */
	public function registerCommonTableExpression(array $fields, string $name): void
	{
		if (isset($this->cteSchemas[$name])) {
			throw new NotUniqueTableAliasException($name);
		}

		$columns = $this->getColumnsFromFields($fields);
		$this->cteSchemas[$name] = new Schema\Table($name, null, $columns);
	}

	/** @param array<string> $columnNames */
	private function registerColumnsForSchema(string $schemaName, array $columnNames, ?string $database): void
	{
		foreach ($columnNames as $column) {
			$this->allColumns[] = ['column' => $column, 'table' => $schemaName, 'database' => $database];
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
			throw new NotUniqueTableAliasException($alias);
		}

		$columnNames = [];
		$uniqueFieldNameMap = [];

		foreach ($fields as $field) {
			if (isset($uniqueFieldNameMap[$field->name])) {
				throw new DuplicateFieldNameException($field->name);
			}

			$field = new QueryResultField(
				$field->name,
				new ExprTypeResult(
					$field->exprType->type,
					$field->exprType->isNullable,
					new ColumnInfo($field->name, $alias, $alias, ColumnInfoTableTypeEnum::SUBQUERY, null),
				),
			);
			$uniqueFieldNameMap[$field->name] = true;
			$columnNames[] = $field->name;
			$this->subquerySchemas[$alias][$field->name] = $field;
		}

		$this->registerColumnsForSchema($alias, $columnNames, null);
	}

	/**
	 * @param non-empty-array<QueryResultField> $fields
	 * @return non-empty-array<string, Schema\Column> name => column
	 * @throws AnalyserException
	 */
	private function getColumnsFromFields(array $fields): array
	{
		$uniqueFieldNameMap = [];
		$columns = [];

		foreach ($fields as $field) {
			if (isset($uniqueFieldNameMap[$field->name])) {
				throw new DuplicateFieldNameException($field->name);
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

	/** @throws AnalyserException */
	private function registerOuterJoinedTable(string $table, ?string $database = null): void
	{
		$type = $this->getTableType($table, $database);
		$database = match ($type) {
			ColumnInfoTableTypeEnum::TABLE => $database ?? $this->dbReflection->getDefaultDatabase(),
			ColumnInfoTableTypeEnum::SUBQUERY => self::SUBQUERY_DB,
		};
		$this->outerJoinedTableMap[$database][$table] = true;
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

	/** @throws AnalyserException */
	public function registerGroupByColumn(ColumnInfo $column): void
	{
		$this->groupByColumns[$this->getColumnDatabaseWithFallback($column)][$column->tableAlias][$column->name] = true;
	}

	/**
	 * @return ResultWithWarnings<ExprTypeResult>
	 * @throws AnalyserException
	 */
	public function resolveColumn(
		string $column,
		?string $table,
		?string $database,
		ColumnResolverFieldBehaviorEnum $fieldBehavior,
		?AnalyserConditionTypeEnum $condition = null,
	): ResultWithWarnings {
		$resolvedColumn = $this->resolveColumnName($column, $table, $database, $fieldBehavior);
		$columnType = $this->getTypeForFullyQualifiedColumn($resolvedColumn->result, $fieldBehavior, $condition);

		if (
			$fieldBehavior === ColumnResolverFieldBehaviorEnum::ASSIGNMENT
			&& $columnType->column?->tableType !== ColumnInfoTableTypeEnum::TABLE
		) {
			throw AnalyserException::fromAnalyserError(
				AnalyserErrorBuilder::createAssignToReadonlyColumnError($column, $table),
			);
		}

		return new ResultWithWarnings($columnType, $resolvedColumn->warnings);
	}

	/** @throws AnalyserException */
	private function getTypeForFullyQualifiedColumn(
		FullyQualifiedColumn $column,
		ColumnResolverFieldBehaviorEnum $fieldBehavior,
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
					return $this->parent->getTypeForFullyQualifiedColumn(
						$column->parentColumn,
						$fieldBehavior,
						$condition,
					);
				} finally {
					if ($this->aggregateFunctionDepth > 0) {
						$this->parent->exitAggregateFunction();
					}
				}
		}

		assert($column instanceof TableFullyQualifiedColumn);
		$columnInfo = $column->columnInfo;
		$columnDbWithFallback = $this->getColumnDatabaseWithFallback($columnInfo);

		if (
			$fieldBehavior === ColumnResolverFieldBehaviorEnum::HAVING
			&& $this->aggregateFunctionDepth === 0
			&& ! isset($this->groupByColumns[$columnDbWithFallback][$columnInfo->tableAlias][$columnInfo->name])
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
				throw AnalyserException::fromAnalyserError(
					AnalyserErrorBuilder::createInvalidHavingColumnError($columnInfo->name),
				);
			}
		}

		$exprType = $this->findColumnExprType($columnInfo->tableAlias, $columnInfo->name, $columnInfo->database)
			?? throw AnalyserException::fromAnalyserError(
				AnalyserErrorBuilder::createUnknownColumnError(
					$columnInfo->name,
					$columnInfo->tableAlias,
					$columnInfo->database,
				),
			);
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

	/**
	 * @return ResultWithWarnings<FullyQualifiedColumn>
	 * @throws AnalyserException
	 */
	private function resolveColumnName(
		string $column,
		?string $table,
		?string $database,
		ColumnResolverFieldBehaviorEnum $fieldBehavior,
		bool $allowParentColumn = true,
	): ResultWithWarnings {
		if (
			$fieldBehavior === ColumnResolverFieldBehaviorEnum::ASSIGNMENT
			&& $this->insertReplaceTargetTable !== null
		) {
			if (
				($table !== null && $table !== $this->insertReplaceTargetTable->name)
				|| ($database !== null && $database !== $this->insertReplaceTargetTable->database)
			) {
				throw AnalyserException::fromAnalyserError(
					AnalyserErrorBuilder::createUnknownColumnError($column, $table, $database),
				);
			}

			$columnSchema = $this->insertReplaceTargetTable->columns[$column] ?? null;

			if ($columnSchema === null) {
				throw AnalyserException::fromAnalyserError(
					AnalyserErrorBuilder::createUnknownColumnError($column, $table, $database),
				);
			}

			return new ResultWithWarnings(
				new TableFullyQualifiedColumn(
					new ColumnInfo(
						$column,
						$this->insertReplaceTargetTable->name,
						$this->insertReplaceTargetTable->name,
						ColumnInfoTableTypeEnum::TABLE,
						$this->insertReplaceTargetTable->database,
					),
				),
				[],
			);
		}

		if (
			$table === null
			&& in_array(
				$fieldBehavior,
				[ColumnResolverFieldBehaviorEnum::HAVING, ColumnResolverFieldBehaviorEnum::ORDER_BY],
				true,
			)
			&& isset($this->fieldList[$column])
		) {
			return new ResultWithWarnings(new FieldListFullyQualifiedColumn($this->fieldList[$column][0][0]), []);
		}

		$candidateTables = array_filter(
			$this->allColumns,
			$table === null
				? static fn (array $t) => $t['column'] === $column
				: static fn (array $t) => $t['column'] === $column && $t['table'] === $table
					&& ($database === null || $database === $t['database']),
		);

		if ($table === null) {
			$candidateFields = $this->fieldList[$column] ?? [];

			if ($fieldBehavior === ColumnResolverFieldBehaviorEnum::GROUP_BY && count($candidateFields) > 0) {
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

				if ($uniqueColumnCount > 1) {
					throw AnalyserException::fromAnalyserError(
						AnalyserErrorBuilder::createAmbiguousColumnError(
							$column,
							$table,
							$isAmbiguousWarning,
						),
					);
				}

				$warnings = [];

				if ($isAmbiguousWarning) {
					$warnings[] = AnalyserErrorBuilder::createAmbiguousColumnError(
						$column,
						$table,
						$isAmbiguousWarning,
					);
				}

				if ($candidateTable !== null) {
					return new ResultWithWarnings(
						new TableFullyQualifiedColumn(
							$this->getColumnInfo($candidateTable, $column, $database),
						),
						$warnings,
					);
				}

				// If there are multiple expressions with the same alias the first one seems to be used.
				return new ResultWithWarnings(
					new FieldListFullyQualifiedColumn($firstExpressionField ?? $firstField),
					$warnings,
				);
			}
		}

		switch (count($candidateTables)) {
			case 0:
				if (! $allowParentColumn) {
					throw AnalyserException::fromAnalyserError(
						AnalyserErrorBuilder::createUnknownColumnError($column, $table, $database),
					);
				}

				$resolvedParentColumn = null;

				if ($this->aggregateFunctionDepth > 0) {
					$this->parent?->enterAggregateFunction();
				}

				try {
					$resolvedParentColumn = $this->parent?->resolveColumnName(
						$column,
						$table,
						$database,
						$fieldBehavior,
						$this->canReferenceGrandParent,
					);
				} catch (AnalyserException) {
				}

				if ($this->aggregateFunctionDepth > 0) {
					$this->parent?->exitAggregateFunction();
				}

				// TODO: add test to make sure that the prioritization is the same as in the database.
				// E.g. SELECT *, (SELECT id*2 id GROUP BY id%2) FROM analyser_test;
				if ($resolvedParentColumn !== null) {
					return new ResultWithWarnings(
						new ParentFullyQualifiedColumn($resolvedParentColumn->result),
						$resolvedParentColumn->warnings,
					);
				}

				if ($table === null) {
					$parentField = $this->parent?->findUniqueItemInFieldList($column);

					if ($parentField !== null) {
						return new ResultWithWarnings(new FieldListFullyQualifiedColumn($parentField), []);
					}

					if ($fieldBehavior !== ColumnResolverFieldBehaviorEnum::FIELD_LIST) {
						$field = $this->findUniqueItemInFieldList($column);

						if ($field !== null) {
							return new ResultWithWarnings(new FieldListFullyQualifiedColumn($field), []);
						}
					}
				}

				throw AnalyserException::fromAnalyserError(
					AnalyserErrorBuilder::createUnknownColumnError($column, $table, $database),
				);
			case 1:
				return new ResultWithWarnings(
					new TableFullyQualifiedColumn(
						$this->getColumnInfo(
							reset($candidateTables)['table'],
							$column,
							reset($candidateTables)['database'],
						),
					),
					[],
				);
			default:
				throw AnalyserException::fromAnalyserError(
					AnalyserErrorBuilder::createAmbiguousColumnError($column, $table),
				);
		}
	}

	/** @throws AnalyserException */
	private function getColumnInfo(string $table, string $column, ?string $database): ColumnInfo
	{
		$database ??= $this->dbReflection->getDefaultDatabase();
		$tableName = $this->tablesByAlias[$table][$database] ?? $table;

		if (isset($this->tableSchemas[$database][$tableName])) {
			return new ColumnInfo($column, $tableName, $table, ColumnInfoTableTypeEnum::TABLE, $database);
		}

		$tableName = $this->tablesByAlias[$table][self::SUBQUERY_DB] ?? $table;

		if (isset($this->subquerySchemas[$table]) || $this->findCteSchema($tableName) !== null) {
			return new ColumnInfo($column, $tableName, $table, ColumnInfoTableTypeEnum::SUBQUERY, null);
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
	public function resolveAllColumns(?string $table, ?string $database = null): array
	{
		$fields = [];

		if ($table !== null) {
			$columnNames = $this->findAllColumnNames($table, $database);

			foreach ($columnNames as $column) {
				$exprType = $this->findColumnExprType($table, $column, $database);

				// This would have already been reported previously, so let's ignore it.
				if ($exprType === null) {
					continue;
				}

				$fields[] = new QueryResultField($column, $exprType);
			}
		} else {
			if ($database !== null) {
				throw new ShouldNotHappenException('It should not be possible to specify database without table.');
			}

			unset($database);

			foreach ($this->allColumns as ['column' => $column, 'table' => $columnTable, 'database' => $database]) {
				$exprType = $this->findColumnExprType($columnTable, $column, $database);

				// This would have already been reported previously, so let's ignore it.
				if ($exprType === null) {
					continue;
				}

				$fields[] = new QueryResultField($column, $exprType);
			}
		}

		return $fields;
	}

	/** @return list<string> */
	private function findAllColumnNames(string $table, ?string $database): array
	{
		$origDatabase = $database;
		$database ??= $this->dbReflection->getDefaultDatabase();
		$normalizedTableName = $this->tablesByAlias[$table][$database] ?? $table;
		$tableSchema = $this->tableSchemas[$database][$normalizedTableName] ?? null;

		if ($tableSchema !== null) {
			return array_keys($tableSchema->columns);
		}

		if ($origDatabase !== null) {
			// TODO: error if schema is not found
			return [];
		}

		$normalizedTableName = $this->tablesByAlias[$table][self::SUBQUERY_DB] ?? $table;
		$cteSchema = $this->findCteSchema($normalizedTableName);

		if ($cteSchema !== null) {
			return array_keys($cteSchema->columns);
		}

		if (isset($this->subquerySchemas[$table])) {
			return array_keys($this->subquerySchemas[$table]);
		}

		// TODO: error if schema is not found
		return [];
	}

	private function findColumnExprType(string $table, string $column, ?string $database): ?ExprTypeResult
	{
		$origDatabase = $database;
		$database ??= $this->dbReflection->getDefaultDatabase();
		$normalizedTableName = $this->tablesByAlias[$table][$database] ?? $table;
		$tableSchema = $this->tableSchemas[$database][$normalizedTableName] ?? null;
		$schemaDatabase = $database;
		$tableType = ColumnInfoTableTypeEnum::TABLE;

		if ($tableSchema === null && $origDatabase === null) {
			$normalizedTableName = $this->tablesByAlias[$table][self::SUBQUERY_DB] ?? $table;
			$tableSchema = $this->findCteSchema($normalizedTableName);
			$schemaDatabase = null;
			$tableType = ColumnInfoTableTypeEnum::SUBQUERY;
			$database = self::SUBQUERY_DB;
		}

		$isOuterTable = $this->outerJoinedTableMap[$database][$table] ?? false;

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
					$tableType,
					$schemaDatabase,
				),
			);
		}

		if (isset($this->subquerySchemas[$table])) {
			$f = $this->subquerySchemas[$table][$column] ?? null;

			return $f?->exprType;
		}

		return null;
	}

	/** @throws AnalyserException */
	private function getTableType(string $alias, ?string $database): ColumnInfoTableTypeEnum
	{
		if (isset($this->tablesByAlias[$alias][$database ?? $this->dbReflection->getDefaultDatabase()])) {
			return ColumnInfoTableTypeEnum::TABLE;
		}

		if ($database === null) {
			if (isset($this->subquerySchemas[$alias])) {
				return ColumnInfoTableTypeEnum::SUBQUERY;
			}

			if ($this->findCteSchema($this->tablesByAlias[$alias][self::SUBQUERY_DB] ?? $alias) !== null) {
				return ColumnInfoTableTypeEnum::SUBQUERY;
			}
		}

		throw AnalyserException::fromAnalyserError(
			AnalyserErrorBuilder::createTableDoesntExistError($alias, $database),
		);
	}

	private function findCteSchema(string $name): ?Schema\Table
	{
		return $this->cteSchemas[$name] ?? $this->parent?->findCteSchema($name);
	}

	/** @throws AnalyserException */
	public function mergeAfterJoin(self $other, Join $join): void
	{
		$duplicateAliases = array_intersect_key($this->tablesByAlias, $other->tablesByAlias);

		foreach (array_keys($duplicateAliases) as $alias) {
			$duplicates = array_intersect_key($this->tablesByAlias[$alias], $other->tablesByAlias[$alias]);
			$hasDbName = count($this->tablesByAlias[$alias]) > 1
				|| count($other->tablesByAlias[$alias]) > 1;

			if (count($duplicates) > 0) {
				throw new NotUniqueTableAliasException(
					$alias,
					$hasDbName
						? array_key_first($duplicates)
						: null,
				);
			}
		}

		// Register current tables as outer-joined, before adding tables from $other
		if ($join->joinType === JoinTypeEnum::RIGHT_OUTER_JOIN) {
			$this->registerAllTablesAsOuterJoin($this);
		}

		$newTablesByAlias = [];

		foreach (array_keys($this->tablesByAlias + $other->tablesByAlias) as $alias) {
			$newTablesByAlias[$alias] = array_merge(
				$this->tablesByAlias[$alias] ?? [],
				$other->tablesByAlias[$alias] ?? [],
			);
		}

		$this->tablesByAlias = $newTablesByAlias;
		$newOuterJoinedTableMap = [];

		foreach (array_keys($this->outerJoinedTableMap + $other->outerJoinedTableMap) as $database) {
			$newOuterJoinedTableMap[$database] = array_merge(
				$this->outerJoinedTableMap[$database] ?? [],
				$other->outerJoinedTableMap[$database] ?? [],
			);
		}

		$this->outerJoinedTableMap = $newOuterJoinedTableMap;
		$newTableSchemas = [];

		foreach (array_keys($this->tableSchemas + $other->tableSchemas) as $database) {
			$newTableSchemas[$database] = array_merge(
				$this->tableSchemas[$database] ?? [],
				$other->tableSchemas[$database] ?? [],
			);
		}

		$this->tableSchemas = $newTableSchemas;

		$duplicateSubqueries = array_intersect_key($this->subquerySchemas, $other->subquerySchemas);

		if (count($duplicateSubqueries) > 0) {
			throw new NotUniqueTableAliasException(array_key_first($duplicateSubqueries));
		}

		$this->subquerySchemas = array_merge($this->subquerySchemas, $other->subquerySchemas);

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

		if ($join->joinType === JoinTypeEnum::LEFT_OUTER_JOIN) {
			$this->registerAllTablesAsOuterJoin($other);
		}

		if ($this->knowledgeBase !== null && $other->knowledgeBase !== null) {
			$this->knowledgeBase = $this->knowledgeBase->and($other->knowledgeBase);
		} elseif ($this->knowledgeBase !== null) {
			$this->knowledgeBase = $other->knowledgeBase;
		}
	}

	/** @throws AnalyserException */
	private function registerAllTablesAsOuterJoin(self $outerJoinedResolver): void
	{
		foreach ($outerJoinedResolver->tablesByAlias as $alias => $dbTables) {
			foreach (array_keys($dbTables) as $database) {
				$this->registerOuterJoinedTable($alias, $database);
			}
		}

		foreach (array_keys($outerJoinedResolver->subquerySchemas) as $alias) {
			$this->registerOuterJoinedTable($alias);
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
				throw AnalyserException::fromAnalyserError(
					AnalyserErrorBuilder::createUnknownColumnError($column),
				);
			case 1:
				return;
			default:
				throw AnalyserException::fromAnalyserError(
					AnalyserErrorBuilder::createAmbiguousColumnError($column),
				);
		}
	}

	public function findTableSchema(string $tableName, ?string $database = null): ?Schema\Table
	{
		return $this->tableSchemas[$database ?? $this->dbReflection->getDefaultDatabase()][$tableName] ?? null;
	}

	/** @return array<string> */
	public function getCollidingSubqueryAndTableAliases(): array
	{
		return array_keys(
			array_intersect_key(
				$this->tablesByAlias,
				$this->subquerySchemas,
			),
		);
	}

	public function hasTableForDelete(string $table, ?string $database = null): bool
	{
		$database ??= $this->dbReflection->getDefaultDatabase();

		if (isset($this->tablesByAlias[$table][$database])) {
			return true;
		}

		if (! isset($this->tableSchemas[$database][$table])) {
			return false;
		}

		foreach ($this->tablesByAlias as $dbTables) {
			if (($dbTables[$database] ?? null) === $table) {
				return false;
			}
		}

		return true;
	}

	public function enterAggregateFunction(): void
	{
		$this->aggregateFunctionDepth++;
	}

	/** @throws AnalyserException */
	public function exitAggregateFunction(): void
	{
		if ($this->aggregateFunctionDepth === 0) {
			throw new ShouldNotHappenException('Invalid state: exiting aggregate function without entering it.');
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

	/** @throws AnalyserException */
	private function getColumnDatabaseWithFallback(ColumnInfo $column): string
	{
		return match ($column->tableType) {
			ColumnInfoTableTypeEnum::SUBQUERY => self::SUBQUERY_DB,
			ColumnInfoTableTypeEnum::TABLE => $column->database
				?? throw new ShouldNotHappenException('Column\'s database is null'),
		};
	}
}
