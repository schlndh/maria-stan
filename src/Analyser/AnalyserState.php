<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\Exception\ShouldNotHappenException;
use MariaStan\Analyser\PlaceholderTypeProvider\PlaceholderTypeProvider;
use MariaStan\Ast\Expr;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\DeleteQuery;
use MariaStan\Ast\Query\InsertBody\InsertBodyTypeEnum;
use MariaStan\Ast\Query\InsertBody\SelectInsertBody;
use MariaStan\Ast\Query\InsertBody\SetInsertBody;
use MariaStan\Ast\Query\InsertBody\ValuesInsertBody;
use MariaStan\Ast\Query\InsertQuery;
use MariaStan\Ast\Query\Query;
use MariaStan\Ast\Query\QueryTypeEnum;
use MariaStan\Ast\Query\ReplaceQuery;
use MariaStan\Ast\Query\SelectQuery\CombinedSelectQuery;
use MariaStan\Ast\Query\SelectQuery\SelectQuery;
use MariaStan\Ast\Query\SelectQuery\SelectQueryTypeEnum;
use MariaStan\Ast\Query\SelectQuery\SimpleSelectQuery;
use MariaStan\Ast\Query\SelectQuery\TableValueConstructorSelectQuery;
use MariaStan\Ast\Query\SelectQuery\WithSelectQuery;
use MariaStan\Ast\Query\SelectQueryCombinatorTypeEnum;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\Subquery;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\Query\TableReference\TableReferenceTypeEnum;
use MariaStan\Ast\Query\TableReference\TableValueConstructor;
use MariaStan\Ast\Query\TableReference\UsingJoinCondition;
use MariaStan\Ast\Query\TruncateQuery;
use MariaStan\Ast\Query\UpdateQuery;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExprTypeEnum;
use MariaStan\Database\FunctionInfo\FunctionInfoHelper;
use MariaStan\Database\FunctionInfo\FunctionInfoRegistry;
use MariaStan\Database\FunctionInfo\FunctionTypeEnum;
use MariaStan\DbReflection\DbReflection;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Parser\Position;
use MariaStan\Schema;

use function array_fill_keys;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reduce;
use function array_unique;
use function array_values;
use function assert;
use function count;
use function in_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function stripos;
use function strtoupper;

final class AnalyserState
{
	/** @var list<AnalyserError> */
	private array $errors = [];
	private ColumnResolver $columnResolver;
	private int $positionalPlaceholderCount = 0;

	/** @var array<string, ReferencedSymbol\Table> name => table */
	private array $referencedTables = [];

	/** @var array<string, array<string, ReferencedSymbol\TableColumn>> table name => column name => column */
	private array $referencedTableColumns = [];
	private ColumnResolverFieldBehaviorEnum $fieldBehavior = ColumnResolverFieldBehaviorEnum::FIELD_LIST;
	private bool $hasAggregateFunctionCalls = false;

	public function __construct(
		private readonly DbReflection $dbReflection,
		private readonly FunctionInfoRegistry $functionInfoRegistry,
		private readonly PlaceholderTypeProvider $placeholderTypeProvider,
		private readonly Query $queryAst,
		private readonly string $query,
		?ColumnResolver $columnResolver = null,
	) {
		$this->columnResolver = $columnResolver ?? new ColumnResolver($this->dbReflection);
	}

	/** @throws AnalyserException */
	public function analyse(): AnalyserResult
	{
		$rowCountRange = null;

		switch ($this->queryAst::getQueryType()) {
			case QueryTypeEnum::SELECT:
				assert($this->queryAst instanceof SelectQuery);
				[$fields, $rowCountRange] = $this->dispatchAnalyseSelectQuery($this->queryAst);
				break;
			case QueryTypeEnum::INSERT:
				// fallthrough intentional
			case QueryTypeEnum::REPLACE:
				assert($this->queryAst instanceof InsertQuery || $this->queryAst instanceof ReplaceQuery);
				$fields = $this->analyseInsertOrReplaceQuery($this->queryAst);
				break;
			case QueryTypeEnum::TRUNCATE:
				assert($this->queryAst instanceof TruncateQuery);
				$this->analyseTruncateQuery($this->queryAst);
				$fields = [];
				break;
			case QueryTypeEnum::UPDATE:
				assert($this->queryAst instanceof UpdateQuery);
				$this->analyseUpdateQuery($this->queryAst);
				$fields = [];
				break;
			case QueryTypeEnum::DELETE:
				assert($this->queryAst instanceof DeleteQuery);
				$fields = $this->analyseDeleteQuery($this->queryAst);
				break;
			default:
				return $this->getUnsupportedQueryTypeResult();
		}

		$referencedSymbols = array_values($this->referencedTables);

		foreach ($this->referencedTableColumns as $columns) {
			$referencedSymbols = array_merge($referencedSymbols, $columns);
		}

		return new AnalyserResult(
			$fields,
			$this->errors,
			$this->positionalPlaceholderCount,
			$referencedSymbols,
			$rowCountRange,
		);
	}

	/**
	 * @return array{0: array<QueryResultField>, 1: ?QueryResultRowCountRange}
	 * @throws AnalyserException
	 */
	private function dispatchAnalyseSelectQuery(SelectQuery $select): array
	{
		switch ($select::getSelectQueryType()) {
			case SelectQueryTypeEnum::SIMPLE:
				assert($select instanceof SimpleSelectQuery);

				return $this->analyseSingleSelectQuery($select);
			case SelectQueryTypeEnum::COMBINED:
				assert($select instanceof CombinedSelectQuery);

				return $this->analyseCombinedSelectQuery($select);
			case SelectQueryTypeEnum::WITH:
				assert($select instanceof WithSelectQuery);

				return $this->analyseWithSelectQuery($select);
			case SelectQueryTypeEnum::TABLE_VALUE_CONSTRUCTOR:
				assert($select instanceof TableValueConstructorSelectQuery);

				return $this->analyseTableValueConstructor($select->tableValueConstructor);
			default:
				$typeVal = $select::getSelectQueryType()->value;
				assert(is_string($typeVal));
				$this->errors[] = new AnalyserError(
					"Unhandled SELECT type {$typeVal}",
					AnalyserErrorTypeEnum::MARIA_STAN_UNSUPPORTED_FEATURE,
				);

				return [[], null];
		}
	}

	/**
	 * @return array{0: array<QueryResultField>, 1: ?QueryResultRowCountRange}
	 * @throws AnalyserException
	 */
	private function analyseWithSelectQuery(WithSelectQuery $select): array
	{
		if ($select->allowRecursive) {
			$this->errors[] = new AnalyserError(
				"WITH RECURSIVE is not currently supported. There may be false positives!",
				AnalyserErrorTypeEnum::MARIA_STAN_UNSUPPORTED_FEATURE,
			);
		}

		foreach ($select->commonTableExpressions as $cte) {
			$subqueryFields = $this->getSubqueryAnalyser($cte->subquery)->analyse()->resultFields ?? [];

			if (count($subqueryFields) === 0) {
				$this->errors[] = new AnalyserError(
					"CTE {$cte->name} doesn't have any columns.",
					AnalyserErrorTypeEnum::INVALID_CTE,
				);

				continue;
			}

			if ($cte->columnList !== null) {
				$fieldCount = count($subqueryFields);
				$columnCount = count($cte->columnList);

				if ($fieldCount !== $columnCount) {
					$this->errors[] = AnalyserErrorBuilder::createDifferentNumberOfWithColumnsError(
						$columnCount,
						$fieldCount,
					);
				}

				$commonCount = min($fieldCount, $columnCount);

				for ($i = 0; $i < $commonCount; $i++) {
					$subqueryFields[$i] = $subqueryFields[$i]->getRenamed($cte->columnList[$i]);
				}
			}

			try {
				$this->columnResolver->registerCommonTableExpression($subqueryFields, $cte->name);
			} catch (AnalyserException $e) {
				$this->errors[] = $e->toAnalyserError();
			}
		}

		return $this->dispatchAnalyseSelectQuery($select->selectQuery);
	}

	/**
	 * @return array{0: array<QueryResultField>, 1: ?QueryResultRowCountRange}
	 * @throws AnalyserException
	 */
	private function analyseCombinedSelectQuery(CombinedSelectQuery $select): array
	{
		$leftFields = $this->getSubqueryAnalyser($select->left, true)->analyse()->resultFields;

		if ($leftFields === null) {
			throw new ShouldNotHappenException('Subquery fields are null, this should not happen.');
		}

		$rightFields = $this->getSubqueryAnalyser($select->right, true)->analyse()->resultFields;

		if ($rightFields === null) {
			throw new ShouldNotHappenException('Subquery fields are null, this should not happen.');
		}

		$countLeft = count($leftFields);
		$countRight = count($rightFields);
		$commonCount = $countLeft;

		if ($countLeft !== $countRight) {
			$commonCount = min($countLeft, $countRight);
			$this->errors[] = AnalyserErrorBuilder::createDifferentNumberOfColumnsError($countLeft, $countRight);
		}

		$fields = [];
		$i = 0;

		for (; $i < $commonCount; $i++) {
			$lf = $leftFields[$i];
			$rf = $rightFields[$i];
			$combinedType = $this->getCombinedType($lf->exprType->type, $rf->exprType->type, $select->combinator);
			$fields[] = new QueryResultField(
				$lf->name,
				new ExprTypeResult($combinedType, $lf->exprType->isNullable || $rf->exprType->isNullable),
			);
		}

		unset($leftFields, $rightFields);
		$this->columnResolver->registerFieldList($fields);
		$this->fieldBehavior = ColumnResolverFieldBehaviorEnum::ORDER_BY;

		foreach ($select->orderBy->expressions ?? [] as $orderByExpr) {
			$this->resolveExprType($orderByExpr->expr);
		}

		if ($select->limit?->count !== null) {
			$this->resolveExprType($select->limit->count);
		}

		if ($select->limit?->offset !== null) {
			$this->resolveExprType($select->limit->offset);
		}

		// TODO: implement row count
		return [$fields, null];
	}

	/**
	 * @return array{0: array<QueryResultField>, 1: ?QueryResultRowCountRange}
	 * @throws AnalyserException
	 */
	private function analyseTableValueConstructor(TableValueConstructor $tvc): array
	{
		$colCounts = [];
		$rowColTypes = [];

		foreach ($tvc->values as $exprs) {
			$colCounts[] = count($exprs);
			$colTypes = [];

			foreach ($exprs as $expr) {
				$colTypes[] = $this->resolveExprType($expr);
			}

			$rowColTypes[] = $colTypes;
		}

		$minColCount = min($colCounts);
		$maxColCount = max($colCounts);

		if ($minColCount !== $maxColCount) {
			$this->errors[] = AnalyserErrorBuilder::createTvcDifferentNumberOfValues($minColCount, $maxColCount);
		}

		$fields = [];
		$rowCount = count($rowColTypes);

		for ($i = 0; $i < $minColCount; $i++) {
			$type = $rowColTypes[0][$i];

			for ($j = 1; $j < $rowCount; $j++) {
				$otherRowType = $rowColTypes[$j][$i];
				$combinedType = $this->getCombinedType(
					$type->type,
					$otherRowType->type,
					SelectQueryCombinatorTypeEnum::UNION,
				);
				$type = new ExprTypeResult(
					$combinedType,
					$type->isNullable || $otherRowType->isNullable,
					// TODO: is there something to combined here?
					null,
					null,
				);
			}

			$fields[] = new QueryResultField(
				$this->getDefaultFieldNameForExpr($tvc->values[0][$i]),
				$type,
			);
		}

		return [$fields, new QueryResultRowCountRange($rowCount, $rowCount)];
	}

	/**
	 * @return array{0: array<QueryResultField>, 1: ?QueryResultRowCountRange}
	 * @throws AnalyserException
	 */
	private function analyseSingleSelectQuery(SimpleSelectQuery $select): array
	{
		$fromClause = $select->from;

		if ($fromClause !== null) {
			try {
				$this->columnResolver = $this->analyseTableReference($fromClause, clone $this->columnResolver)[1];
			} catch (AnalyserException | DbReflectionException $e) {
				$this->errors[] = $e->toAnalyserError();
			}
		}

		$whereResult = $havingResult = null;

		if ($select->where !== null) {
			$whereResult = $this->resolveExprType(
				$select->where,
				AnalyserConditionTypeEnum::TRUTHY,
				canReferenceGrandParent: true,
			);
			$this->columnResolver->addKnowledge($whereResult->knowledgeBase);
		}

		$fields = [];
		$this->fieldBehavior = ColumnResolverFieldBehaviorEnum::FIELD_LIST;

		foreach ($select->select as $selectExpr) {
			switch ($selectExpr::getSelectExprType()) {
				case SelectExprTypeEnum::REGULAR_EXPR:
					assert($selectExpr instanceof RegularExpr);
					$expr = $this->removeUnaryPlusPrefix($selectExpr->expr);
					$resolvedExpr = $this->resolveExprType($expr, null, $select->groupBy !== null, true);
					$resolvedField = new QueryResultField(
						$selectExpr->alias ?? $this->getDefaultFieldNameForExpr($expr),
						$resolvedExpr,
					);
					$fields[] = $resolvedField;
					$this->columnResolver->registerField(
						$resolvedField,
						$selectExpr->expr::getExprType() === Expr\ExprTypeEnum::COLUMN,
					);
					break;
				case SelectExprTypeEnum::ALL_COLUMNS:
					assert($selectExpr instanceof AllColumns);
					$allFields = $this->columnResolver->resolveAllColumns($selectExpr->tableName?->name);

					foreach ($allFields as $field) {
						$this->columnResolver->registerField($field, true);
						$this->recordColumnReference($field->exprType->column);
					}

					$fields = array_merge($fields, $allFields);
					unset($allFields);
					break;
			}
		}

		$this->fieldBehavior = ColumnResolverFieldBehaviorEnum::GROUP_BY;

		foreach ($select->groupBy->expressions ?? [] as $groupByExpr) {
			$exprType = $this->resolveExprType($groupByExpr->expr, canReferenceGrandParent: true);

			if ($exprType->column === null) {
				continue;
			}

			$this->columnResolver->registerGroupByColumn($exprType->column);
		}

		if ($select->having !== null) {
			$this->fieldBehavior = ColumnResolverFieldBehaviorEnum::HAVING;
			$havingResult = $this->resolveExprType($select->having, canReferenceGrandParent: true);
		}

		$this->fieldBehavior = ColumnResolverFieldBehaviorEnum::ORDER_BY;

		foreach ($select->orderBy->expressions ?? [] as $orderByExpr) {
			$this->resolveExprType($orderByExpr->expr, canReferenceGrandParent: true);
		}

		if ($select->limit?->count !== null) {
			$this->resolveExprType($select->limit->count);
		}

		if ($select->limit?->offset !== null) {
			$this->resolveExprType($select->limit->offset);
		}

		$rowCount = null;

		if ($select->from === null) {
			$rowCount = QueryResultRowCountRange::createFixed(1)->applyWhere($whereResult);
		} elseif ($this->hasAggregateFunctionCalls && $select->groupBy === null) {
			// WHERE doesn't matter here.
			$rowCount = QueryResultRowCountRange::createFixed(1);
		}

		$rowCount = $rowCount?->applyWhere($havingResult)
			->applyLimitClause($select->limit);

		return [$fields, $rowCount];
	}

	/**
	 * @return array{array<string>, ColumnResolver} [table names in order, column resolver]
	 * @throws AnalyserException|DbReflectionException
	 */
	private function analyseTableReference(TableReference $fromClause, ColumnResolver $columnResolver): array
	{
		switch ($fromClause::getTableReferenceType()) {
			case TableReferenceTypeEnum::TABLE:
				assert($fromClause instanceof Table);
				$columnResolver = clone $columnResolver;

				try {
					$tableType = $columnResolver->registerTable($fromClause->name->name, $fromClause->alias);

					if ($tableType === ColumnInfoTableTypeEnum::TABLE) {
						$this->referencedTables[$fromClause->name->name]
							??= new ReferencedSymbol\Table($fromClause->name->name);
					}
				} catch (AnalyserException | DbReflectionException $e) {
					$this->errors[] = $e->toAnalyserError();
				}

				return [[$fromClause->alias ?? $fromClause->name->name], $columnResolver];
			case TableReferenceTypeEnum::SUBQUERY:
				assert($fromClause instanceof Subquery);
				$columnResolver = clone $columnResolver;
				$subqueryResult = $this->getSubqueryAnalyser($fromClause->query)->analyse();

				try {
					$columnResolver->registerSubquery(
						$subqueryResult->resultFields ?? [],
						$fromClause->getAliasOrThrow(),
					);
				} catch (AnalyserException $e) {
					$this->errors[] = $e->toAnalyserError();
				}

				return [[$fromClause->getAliasOrThrow()], $columnResolver];
			case TableReferenceTypeEnum::JOIN:
				assert($fromClause instanceof Join);
				[$leftTables, $leftCr] = $this->analyseTableReference($fromClause->leftTable, $columnResolver);
				[$rightTables, $rightCr] = $this->analyseTableReference($fromClause->rightTable, $columnResolver);
				$leftCr->mergeAfterJoin($rightCr, $fromClause);
				$columnResolver = $leftCr;
				unset($leftCr, $rightCr);
				$bakResolver = $this->columnResolver;
				$this->columnResolver = $columnResolver;

				$onResult = match (true) {
					$fromClause->joinCondition instanceof Expr\Expr
						=> $this->resolveExprType($fromClause->joinCondition, AnalyserConditionTypeEnum::TRUTHY),
					/** This is checked in {@see ColumnResolver::mergeAfterJoin()} */
					$fromClause->joinCondition instanceof UsingJoinCondition => 1,
					$fromClause->joinCondition === null => null,
				};

				$this->columnResolver = $bakResolver;

				if ($fromClause->joinType === JoinTypeEnum::LEFT_OUTER_JOIN) {
					foreach ($rightTables as $rightTable) {
						$columnResolver->registerOuterJoinedTable($rightTable);
					}
				} elseif ($fromClause->joinType === JoinTypeEnum::RIGHT_OUTER_JOIN) {
					foreach ($leftTables as $leftTable) {
						$columnResolver->registerOuterJoinedTable($leftTable);
					}
				} elseif ($onResult instanceof ExprTypeResult) {
					$columnResolver->addKnowledge($onResult->knowledgeBase);
				}

				return [array_merge($leftTables, $rightTables), $columnResolver];
			case TableReferenceTypeEnum::TABLE_VALUE_CONSTRUCTOR:
				assert($fromClause instanceof TableValueConstructor);
				$columnResolver = clone $columnResolver;
				[$tvcFields] = $this->analyseTableValueConstructor($fromClause);

				try {
					$columnResolver->registerSubquery($tvcFields, $fromClause->getAliasOrThrow());
				} catch (AnalyserException $e) {
					$this->errors[] = $e->toAnalyserError();
				}

				return [[$fromClause->getAliasOrThrow()], $columnResolver];
		}
	}

	/**
	 * @return array<QueryResultField>
	 * @throws AnalyserException
	 */
	private function analyseDeleteQuery(DeleteQuery $query): array
	{
		try {
			$this->columnResolver = $this->analyseTableReference($query->table, clone $this->columnResolver)[1];

			// don't report missing tables to delete if the table reference is not parsed successfully
			foreach ($query->tablesToDelete as $table) {
				if ($this->columnResolver->hasTableForDelete($table->name)) {
					continue;
				}

				// Don't report that table doesn't exist twice in simple "DELETE FROM missing_table".
				if (
					$query->table instanceof Table
					&& $query->table->alias === null
					&& count($query->tablesToDelete) === 1
					&& $query->table->name->name === $table->name
				) {
					continue;
				}

				$this->errors[] = AnalyserErrorBuilder::createTableDoesntExistError($table->name);
			}
		} catch (AnalyserException | DbReflectionException $e) {
			$this->errors[] = $e->toAnalyserError();
		}

		foreach ($this->columnResolver->getCollidingSubqueryAndTableAliases() as $alias) {
			$this->errors[] = AnalyserErrorBuilder::createNotUniqueTableAliasError($alias);
		}

		if ($query->where !== null) {
			$this->resolveExprType($query->where);
		}

		foreach ($query->orderBy->expressions ?? [] as $orderByExpr) {
			$this->resolveExprType($orderByExpr->expr);
		}

		if ($query->limit !== null) {
			$this->resolveExprType($query->limit);
		}

		// TODO: DELETE ... RETURNING
		return [];
	}

	/** @throws AnalyserException */
	private function analyseUpdateQuery(UpdateQuery $query): void
	{
		try {
			$this->columnResolver = $this->analyseTableReference($query->table, clone $this->columnResolver)[1];
		} catch (AnalyserException | DbReflectionException $e) {
			$this->errors[] = $e->toAnalyserError();
		}

		foreach ($query->assignments as $assignment) {
			$this->resolveExprType($assignment);
		}

		if ($query->where !== null) {
			$this->resolveExprType($query->where);
		}

		foreach ($query->orderBy->expressions ?? [] as $orderByExpr) {
			$this->resolveExprType($orderByExpr->expr);
		}

		if ($query->limit !== null) {
			$this->resolveExprType($query->limit);
		}
	}

	/**
	 * @return array<QueryResultField>
	 * @throws AnalyserException
	 */
	private function analyseInsertOrReplaceQuery(InsertQuery|ReplaceQuery $query): array
	{
		static $mockPosition = null;
		$mockPosition ??= new Position(0, 0, 0);
		assert($mockPosition instanceof Position);
		$tableReferenceNode = new Table($mockPosition, $mockPosition, $query->tableName);

		try {
			$this->columnResolver = $this->analyseTableReference($tableReferenceNode, clone $this->columnResolver)[1];
		} catch (AnalyserException | DbReflectionException $e) {
			$this->errors[] = $e->toAnalyserError();
		}

		$tableSchema = $this->columnResolver->findTableSchema($query->tableName->name);
		$setColumnNames = [];
		$onDuplicateKeyAnalyser = $this;

		switch ($query->insertBody::getInsertBodyType()) {
			case InsertBodyTypeEnum::SELECT:
				assert($query->insertBody instanceof SelectInsertBody);

				foreach ($query->insertBody->columnList ?? [] as $column) {
					$this->resolveExprType($column);
				}

				$onDuplicateKeyAnalyser = $this->getSubqueryAnalyser($query->insertBody->selectQuery);
				$selectResult = $onDuplicateKeyAnalyser->analyse()->resultFields
					?? [];

				// if $selectResult is empty (e.g. missing table) then there should already be an error reported.
				if ($tableSchema === null || count($selectResult) === 0) {
					break;
				}

				$onDuplicateKeyAnalyser->fieldBehavior = ColumnResolverFieldBehaviorEnum::FIELD_LIST;
				$setColumnNames = $query->insertBody->columnList !== null
					? array_map(static fn (Expr\Column $c) => $c->name, $query->insertBody->columnList)
					: array_keys($tableSchema->columns);
				$expectedCount = count($setColumnNames);

				if ($expectedCount === count($selectResult)) {
					break;
				}

				$this->errors[] = AnalyserErrorBuilder::createMismatchedInsertColumnCountError(
					$expectedCount,
					count($selectResult),
				);

				break;
			case InsertBodyTypeEnum::SET:
				assert($query->insertBody instanceof SetInsertBody);

				foreach ($query->insertBody->assignments as $expr) {
					$setColumnNames[] = $expr->target->name;
					$this->resolveExprType($expr);
				}

				break;
			case InsertBodyTypeEnum::VALUES:
				assert($query->insertBody instanceof ValuesInsertBody);

				foreach ($query->insertBody->columnList ?? [] as $column) {
					$this->resolveExprType($column);
				}

				foreach ($query->insertBody->values as $tuple) {
					foreach ($tuple as $expr) {
						$this->resolveExprType($expr);
					}
				}

				if ($tableSchema === null) {
					break;
				}

				$setColumnNames = $query->insertBody->columnList !== null
					? array_map(static fn (Expr\Column $c) => $c->name, $query->insertBody->columnList)
					: array_keys($tableSchema->columns);
				$expectedCount = count($setColumnNames);

				foreach ($query->insertBody->values as $tuple) {
					if (count($tuple) === $expectedCount) {
						continue;
					}

					$this->errors[] = AnalyserErrorBuilder::createMismatchedInsertColumnCountError(
						$expectedCount,
						count($tuple),
					);

					// Report only 1 mismatch. The mismatches are probably all going to be the same.
					break;
				}

				break;
		}

		$this->fieldBehavior = ColumnResolverFieldBehaviorEnum::ASSIGNMENT;

		if ($tableSchema !== null) {
			$this->columnResolver->registerInsertReplaceTargetTable($tableSchema);

			if ($this !== $onDuplicateKeyAnalyser) {
				$onDuplicateKeyAnalyser->columnResolver->registerInsertReplaceTargetTable($tableSchema);
			}
		}

		if ($query instanceof InsertQuery) {
			foreach ($query->onDuplicateKeyUpdate ?? [] as $assignment) {
				$this->resolveExprType($assignment->target);
				$onDuplicateKeyAnalyser->resolveExprType($assignment->expression);
			}
		}

		$setColumNamesMap = array_fill_keys($setColumnNames, 1);

		foreach ($tableSchema->columns ?? [] as $name => $column) {
			if (
				isset($setColumNamesMap[$name])
				|| $column->defaultValue !== null
				|| $column->isNullable
				|| $column->isAutoIncrement
				// ENUMs default to the first value if NOT NULL and there is no DEFAULT value specified
				|| $column->type::getTypeEnum() === Schema\DbType\DbTypeEnum::ENUM
			) {
				continue;
			}

			$this->errors[] = AnalyserErrorBuilder::createMissingValueForColumnError($name);
		}

		// TODO: INSERT ... ON DUPLICATE KEY
		// TODO: INSERT ... RETURNING
		return [];
	}

	/** @throws AnalyserException */
	private function analyseTruncateQuery(TruncateQuery $query): void
	{
		try {
			$this->dbReflection->findTableSchema($query->tableName->name);
		} catch (DbReflectionException $e) {
			$this->errors[] = $e->toAnalyserError();
		}
	}

	/** @throws AnalyserException */
	private function resolveExprType(
		Expr\Expr $expr,
		?AnalyserConditionTypeEnum $condition = null,
		// false doesn't mean that the result set is empty, it may be empty.
		bool $isNonEmptyAggResultSet = false,
		bool $canReferenceGrandParent = false,
	): ExprTypeResult {
		// TODO: handle all expression types
		switch ($expr::getExprType()) {
			case Expr\ExprTypeEnum::COLUMN:
				assert($expr instanceof Expr\Column);

				try {
					$resolvedColumn = $this->columnResolver->resolveColumn(
						$expr->name,
						$expr->tableName?->name,
						$this->fieldBehavior,
						$condition,
					);
					$this->errors = array_merge($this->errors, $resolvedColumn->warnings);
					$this->recordColumnReference($resolvedColumn->result->column);

					return $resolvedColumn->result;
				} catch (AnalyserException $e) {
					$this->errors[] = $e->toAnalyserError();
				}

				return new ExprTypeResult(new Schema\DbType\MixedType(), true);
			case Expr\ExprTypeEnum::LITERAL_INT:
				assert($expr instanceof Expr\LiteralInt);

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					false,
					null,
					$this->createKnowledgeBaseForLiteralExpression($expr, $condition),
				);
			case Expr\ExprTypeEnum::LITERAL_FLOAT:
				assert($expr instanceof Expr\LiteralFloat);
				$content = $this->getNodeContent($expr);
				$isExponentNotation = stripos($content, 'e') !== false;

				return new ExprTypeResult(
					$isExponentNotation
						? new Schema\DbType\FloatType()
						: new Schema\DbType\DecimalType(),
					false,
					null,
					$this->createKnowledgeBaseForLiteralExpression($expr, $condition),
				);
			case Expr\ExprTypeEnum::LITERAL_NULL:
				assert($expr instanceof Expr\LiteralNull);

				return new ExprTypeResult(
					new Schema\DbType\NullType(),
					true,
					null,
					$this->createKnowledgeBaseForLiteralExpression($expr, $condition),
				);
			case Expr\ExprTypeEnum::LITERAL_STRING:
				assert($expr instanceof Expr\LiteralString);

				return new ExprTypeResult(
					new Schema\DbType\VarcharType(),
					false,
					null,
					$this->createKnowledgeBaseForLiteralExpression($expr, $condition),
				);
			case Expr\ExprTypeEnum::INTERVAL:
				assert($expr instanceof Expr\Interval);
				$innerCondition = match ($condition) {
					null => null,
					AnalyserConditionTypeEnum::NULL => AnalyserConditionTypeEnum::NULL,
					default => AnalyserConditionTypeEnum::NOT_NULL,
				};
				$timeQuantityResult = $this->resolveExprType(
					$expr->timeQuantity,
					$innerCondition,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);

				return new ExprTypeResult(
					new Schema\DbType\DateTimeType(),
					$timeQuantityResult->isNullable,
					null,
					$timeQuantityResult->knowledgeBase,
				);
			case Expr\ExprTypeEnum::UNARY_OP:
				assert($expr instanceof Expr\UnaryOp);
				$innerCondition = $condition;
				$isTruthinessUncertain = false;

				if ($innerCondition !== null && $expr->operation === Expr\UnaryOpTypeEnum::LOGIC_NOT) {
					$innerCondition = match ($innerCondition) {
						AnalyserConditionTypeEnum::TRUTHY => AnalyserConditionTypeEnum::FALSY,
						AnalyserConditionTypeEnum::FALSY => AnalyserConditionTypeEnum::TRUTHY,
						// NULL(NOT(a)) <=> NULL(a), NOT_NULL(NOT(a)) <=> NOT_NULL(a)
						default => $innerCondition,
					};
				} elseif ($innerCondition !== null && $expr->operation === Expr\UnaryOpTypeEnum::BITWISE_NOT) {
					// ~0, ~1 are non-zero, but ~18446744073709551615 = 0.
					$innerCondition = match ($innerCondition) {
						AnalyserConditionTypeEnum::NULL => AnalyserConditionTypeEnum::NULL,
						default => AnalyserConditionTypeEnum::NOT_NULL,
					};
					$isTruthinessUncertain = true;
				}

				$resolvedInnerExpr = $this->resolveExprType(
					$expr->expression,
					$innerCondition,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);

				$type = match ($expr->operation) {
					Expr\UnaryOpTypeEnum::PLUS => $resolvedInnerExpr->type,
					Expr\UnaryOpTypeEnum::MINUS => match ($resolvedInnerExpr->type::getTypeEnum()) {
						Schema\DbType\DbTypeEnum::INT, Schema\DbType\DbTypeEnum::DECIMAL => $resolvedInnerExpr->type,
						Schema\DbType\DbTypeEnum::DATETIME => new Schema\DbType\DecimalType(),
						Schema\DbType\DbTypeEnum::UNSIGNED_INT => new Schema\DbType\IntType(),
						default => new Schema\DbType\FloatType(),
					},
					Expr\UnaryOpTypeEnum::BINARY => new Schema\DbType\VarcharType(),
					Expr\UnaryOpTypeEnum::BITWISE_NOT => new Schema\DbType\UnsignedIntType(),
					default => new Schema\DbType\IntType(),
				};

				$knowledgeBase = $resolvedInnerExpr->knowledgeBase;

				if ($isTruthinessUncertain) {
					$knowledgeBase = $knowledgeBase?->removeTruthiness();
				}

				return new ExprTypeResult($type, $resolvedInnerExpr->isNullable, null, $knowledgeBase);
			case Expr\ExprTypeEnum::BINARY_OP:
				assert($expr instanceof Expr\BinaryOp);
				$innerConditionLeft = $innerConditionRight = null;
				// NULL = don't combine, true = AND, false = OR.
				$kbCombinineWithAnd = null;
				$nullUnsafeComparisonOperators = [
					Expr\BinaryOpTypeEnum::EQUAL,
					Expr\BinaryOpTypeEnum::NOT_EQUAL,
					Expr\BinaryOpTypeEnum::LOWER,
					Expr\BinaryOpTypeEnum::LOWER_OR_EQUAL,
					Expr\BinaryOpTypeEnum::GREATER,
					Expr\BinaryOpTypeEnum::GREATER_OR_EQUAL,
				];
				$nullUnsafeArithmeticOperators = [
					Expr\BinaryOpTypeEnum::PLUS,
					Expr\BinaryOpTypeEnum::MINUS,
					Expr\BinaryOpTypeEnum::MULTIPLICATION,
					Expr\BinaryOpTypeEnum::SHIFT_LEFT,
					Expr\BinaryOpTypeEnum::SHIFT_RIGHT,
				];
				$divOperators = [
					Expr\BinaryOpTypeEnum::DIVISION,
					Expr\BinaryOpTypeEnum::INT_DIVISION,
					Expr\BinaryOpTypeEnum::MODULO,
				];
				$isTruthinessUncertain = true;

				// TODO: handle $condition for <=>, REGEXP
				if (
					(
						$condition === AnalyserConditionTypeEnum::TRUTHY
						|| $condition === AnalyserConditionTypeEnum::FALSY
					)
					&& (
						$expr->operation === Expr\BinaryOpTypeEnum::LOGIC_AND
						|| $expr->operation === Expr\BinaryOpTypeEnum::LOGIC_OR
					)
				) {
					$innerConditionLeft = $innerConditionRight = $condition;
					$kbCombinineWithAnd = $expr->operation === Expr\BinaryOpTypeEnum::LOGIC_AND;

					if ($condition === AnalyserConditionTypeEnum::FALSY) {
						$kbCombinineWithAnd = ! $kbCombinineWithAnd;
					}
				} elseif ($expr->operation === Expr\BinaryOpTypeEnum::BITWISE_OR) {
					$innerConditionLeft = $innerConditionRight = match ($condition) {
						AnalyserConditionTypeEnum::TRUTHY => AnalyserConditionTypeEnum::NOT_NULL,
						default => $condition,
					};
					$kbCombinineWithAnd = $innerConditionLeft === AnalyserConditionTypeEnum::NOT_NULL
						|| $innerConditionLeft === AnalyserConditionTypeEnum::FALSY;
				} elseif (
					in_array($expr->operation, $nullUnsafeComparisonOperators, true)
					|| in_array($expr->operation, $nullUnsafeArithmeticOperators, true)
					|| $expr->operation === Expr\BinaryOpTypeEnum::BITWISE_AND
				) {
					$innerConditionLeft = $innerConditionRight = match ($condition) {
						null => null,
						AnalyserConditionTypeEnum::NULL => AnalyserConditionTypeEnum::NULL,
						// For now, we can only determine that none of the operands can be NULL.
						default => AnalyserConditionTypeEnum::NOT_NULL,
					};
					$kbCombinineWithAnd = $innerConditionLeft === AnalyserConditionTypeEnum::NOT_NULL;
				} elseif (in_array($expr->operation, $divOperators, true)) {
					[$innerConditionLeft, $innerConditionRight] = match ($condition) {
						null,
						// We'd have to consider both FALSY and NULL for right side.
						AnalyserConditionTypeEnum::NULL => [null, null],
						// For now, we can only determine that none of the operands can be NULL.
						default => [AnalyserConditionTypeEnum::NOT_NULL, AnalyserConditionTypeEnum::TRUTHY],
					};

					if ($condition === AnalyserConditionTypeEnum::NOT_NULL) {
						$isTruthinessUncertain = false;
					}

					$kbCombinineWithAnd = true;
				} elseif (
					$expr->operation === Expr\BinaryOpTypeEnum::LOGIC_XOR
					|| $expr->operation === Expr\BinaryOpTypeEnum::BITWISE_XOR
				) {
					$innerConditionLeft = $innerConditionRight = match ($condition) {
						null => null,
						AnalyserConditionTypeEnum::NULL => $condition,
						// For now, we can only determine that none of the operands can be NULL.
						default => AnalyserConditionTypeEnum::NOT_NULL,
					};
					$kbCombinineWithAnd = $innerConditionLeft === AnalyserConditionTypeEnum::NOT_NULL;
				}

				$leftResult = $this->resolveExprType(
					$expr->left,
					$innerConditionLeft,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);
				$rightResult = $this->resolveExprType(
					$expr->right,
					$innerConditionRight,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);
				$type = null;

				if (
					(
						$expr->operation === Expr\BinaryOpTypeEnum::PLUS
						|| $expr->operation === Expr\BinaryOpTypeEnum::MINUS
					) && $expr->right::getExprType() === Expr\ExprTypeEnum::INTERVAL
				) {
					$type = new Schema\DbType\DateTimeType();
					$isNullable = $leftResult->isNullable
						|| $rightResult->isNullable
						|| $leftResult->type::getTypeEnum() !== Schema\DbType\DbTypeEnum::DATETIME;

					if ($kbCombinineWithAnd !== null) {
						if (
							$condition === AnalyserConditionTypeEnum::NULL
							&& $leftResult->type::getTypeEnum() !== Schema\DbType\DbTypeEnum::DATETIME
							// if it is "+ INTERVAL NULL" then it will always be NULL.
							&& $rightResult->knowledgeBase?->truthiness !== true
						) {
							// "a + INTERVAL b" can be null is also null if "a" is not valid date,
							$kbCombinineWithAnd = null;
						}
					}
				} else {
					$lt = $leftResult->type::getTypeEnum();
					$rt = $rightResult->type::getTypeEnum();
					$typesInvolved = [
						$lt->value => 1,
						$rt->value => 1,
					];
					$getIntType = static fn () => isset($typesInvolved[Schema\DbType\DbTypeEnum::UNSIGNED_INT->value])
						? new Schema\DbType\UnsignedIntType()
						: new Schema\DbType\IntType();
					$isComparisonOperator = in_array(
						$expr->operation,
						[
							Expr\BinaryOpTypeEnum::EQUAL,
							Expr\BinaryOpTypeEnum::NOT_EQUAL,
							Expr\BinaryOpTypeEnum::NULL_SAFE_EQUAL,
							Expr\BinaryOpTypeEnum::GREATER,
							Expr\BinaryOpTypeEnum::GREATER_OR_EQUAL,
							Expr\BinaryOpTypeEnum::LOWER,
							Expr\BinaryOpTypeEnum::LOWER_OR_EQUAL,
						],
						true,
					);

					if (isset($typesInvolved[Schema\DbType\DbTypeEnum::TUPLE->value])) {
						if (! $isComparisonOperator) {
							$this->errors[] = AnalyserErrorBuilder::createInvalidBinaryOpUsageError(
								$expr->operation,
								$lt,
								$rt,
							);
							$type = new Schema\DbType\MixedType();
						} else {
							$this->checkSameTypeShape($leftResult->type, $rightResult->type);
							$type = new Schema\DbType\IntType();
						}
					} elseif (
						isset($typesInvolved[Schema\DbType\DbTypeEnum::NULL->value])
						&& $expr->operation !== Expr\BinaryOpTypeEnum::NULL_SAFE_EQUAL
					) {
						$type = new Schema\DbType\NullType();
					} elseif ($isComparisonOperator) {
						$type = new Schema\DbType\IntType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::MIXED->value])) {
						$type = new Schema\DbType\MixedType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::VARCHAR->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
							? $getIntType()
							: new Schema\DbType\FloatType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::FLOAT->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
							? $getIntType()
							: new Schema\DbType\FloatType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::DECIMAL->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
							? $getIntType()
							: new Schema\DbType\DecimalType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::UNSIGNED_INT->value])) {
						$type = match ($expr->operation) {
							Expr\BinaryOpTypeEnum::MODULO => match ($lt) {
								Schema\DbType\DbTypeEnum::INT, Schema\DbType\DbTypeEnum::UNSIGNED_INT,
								Schema\DbType\DbTypeEnum::DECIMAL, Schema\DbType\DbTypeEnum::FLOAT,
								Schema\DbType\DbTypeEnum::NULL, Schema\DbType\DbTypeEnum::MIXED
									=> $leftResult->type,
								Schema\DbType\DbTypeEnum::DATETIME => new Schema\DbType\IntType(),
								default => new Schema\DbType\FloatType(),
							},
							Expr\BinaryOpTypeEnum::DIVISION => new Schema\DbType\DecimalType(),
							default => isset($typesInvolved[Schema\DbType\DbTypeEnum::DATETIME->value])
								&& $expr->operation !== Expr\BinaryOpTypeEnum::INT_DIVISION
								? new Schema\DbType\IntType()
								: new Schema\DbType\UnsignedIntType(),
						};
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::INT->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::DIVISION
							? new Schema\DbType\DecimalType()
							: new Schema\DbType\IntType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::DATETIME->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::DIVISION
							? new Schema\DbType\DecimalType()
							: $getIntType();
					}

					$isNullable = (
						($leftResult->isNullable || $rightResult->isNullable)
						&& $expr->operation !== Expr\BinaryOpTypeEnum::NULL_SAFE_EQUAL
					)
						// It can be division by 0 in which case MariaDB returns null.
						|| in_array($expr->operation, [
							Expr\BinaryOpTypeEnum::DIVISION,
							Expr\BinaryOpTypeEnum::INT_DIVISION,
							Expr\BinaryOpTypeEnum::MODULO,
						], true);
				}

				// TODO: Analyze the rest of the operators
				$type ??= new Schema\DbType\FloatType();
				$knowledgeBase = null;

				if (
					$leftResult->knowledgeBase !== null
					&& $rightResult->knowledgeBase !== null
					&& $kbCombinineWithAnd !== null
				) {
					$knowledgeBase = $kbCombinineWithAnd
						? $leftResult->knowledgeBase->and($rightResult->knowledgeBase)
						: $leftResult->knowledgeBase->or($rightResult->knowledgeBase);
				}

				// This fallback should handle simple conditions broken by some inner expression which doesn't handle
				// conditions yet. TODO: remove this once it's no longer necessary.
				if ($condition === AnalyserConditionTypeEnum::TRUTHY && $knowledgeBase === null) {
					if ($expr->operation === Expr\BinaryOpTypeEnum::LOGIC_AND) {
						$knowledgeBase = $leftResult->knowledgeBase ?? $rightResult->knowledgeBase;
					} elseif ($expr->operation === Expr\BinaryOpTypeEnum::LOGIC_OR) {
						$knowledgeBase = AnalyserKnowledgeBase::createEmpty();
					}
				}

				// TODO: In many cases I'm relaxing the condition, because I can't check the real condition statically.
				// E.g. TRUTHY(5 = 1) becomes TRUTHY(5 IS NOT NULL AND 1 IS NOT NULL). So I have to remove truthiness
				// in these cases.
				if ($isTruthinessUncertain) {
					$knowledgeBase = $knowledgeBase?->removeTruthiness();
				}

				return new ExprTypeResult($type, $isNullable, null, $knowledgeBase);
			case Expr\ExprTypeEnum::SUBQUERY:
				assert($expr instanceof Expr\Subquery);
				// TODO: handle $condition
				// TODO: handle $isNonEmptyAggResultSet
				$subqueryAnalyser = $this->getSubqueryAnalyser($expr->query, $canReferenceGrandParent);
				$result = $subqueryAnalyser->analyse();
				$canBeEmpty = ($result->rowCountRange->min ?? 0) === 0;

				if ($result->resultFields === null) {
					return new ExprTypeResult(
						new Schema\DbType\MixedType(),
						// TODO: Change it to false if we can statically determine that the query will always return
						// a result: e.g. SELECT 1
						$canBeEmpty,
					);
				}

				if (count($result->resultFields) === 1) {
					return new ExprTypeResult(
						$result->resultFields[0]->exprType->type,
						// TODO: Change it to false if we can statically determine that the query will always return
						// a result: e.g. SELECT 1
						// TODO: Fix this for "x IN (SELECT ...)".
						$canBeEmpty || $result->resultFields[0]->exprType->isNullable,
					);
				}

				$innerTypes = array_map(static fn (QueryResultField $f) => $f->exprType->type, $result->resultFields);

				return new ExprTypeResult(
					new Schema\DbType\TupleType($innerTypes, true),
					// Tuple cannot be null, only its elements can be null.
					false,
				);
			case Expr\ExprTypeEnum::IS:
				assert($expr instanceof Expr\Is);
				$fixedKnowledgeBase = null;

				if ($condition === null) {
					$innerCondition = null;
				} else {
					$innerCondition = match ($expr->test) {
						true => AnalyserConditionTypeEnum::TRUTHY,
						false => AnalyserConditionTypeEnum::FALSY,
						null => AnalyserConditionTypeEnum::NULL,
					};

					if ($condition === AnalyserConditionTypeEnum::FALSY) {
						$innerCondition = match ($innerCondition) {
							// $innerCondition cannot be NOT_NULL at ths point, so we don't have to test it.
							AnalyserConditionTypeEnum::NULL => AnalyserConditionTypeEnum::NOT_NULL,
							// "NOT(x IS TRUE)" can mean "x IS TRUE" or "x IS NULL", similarly for "NOT(x IS FALSE)"
							default => null,
						};
					} elseif ($condition !== AnalyserConditionTypeEnum::TRUTHY) {
						// TODO: report error: NULL/NOT_NULL $condition is never/always satisfied.
						$fixedKnowledgeBase = AnalyserKnowledgeBase::createFixed(
							$condition === AnalyserConditionTypeEnum::NOT_NULL,
						);
						$innerCondition = null;
					}
				}

				// Make sure there are no errors on the left of IS.
				$exprResult = $this->resolveExprType(
					$expr->expression,
					$innerCondition,
					canReferenceGrandParent: $canReferenceGrandParent,
				);

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					false,
					null,
					$fixedKnowledgeBase ?? $exprResult->knowledgeBase,
				);
			case Expr\ExprTypeEnum::BETWEEN:
				assert($expr instanceof Expr\Between);
				// TODO: handle $condition
				$isNullable = array_reduce(
					array_map(
						fn (Expr\Expr $e) => $this->resolveExprType(
							$e,
							null,
							$isNonEmptyAggResultSet,
							$canReferenceGrandParent,
						),
						[$expr->expression, $expr->min, $expr->max],
					),
					static fn (bool $isNullable, ExprTypeResult $f) => $isNullable || $f->isNullable,
					false,
				);

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					$isNullable,
				);
			case Expr\ExprTypeEnum::PLACEHOLDER:
				$this->positionalPlaceholderCount++;
				assert($expr instanceof Expr\Placeholder);

				return new ExprTypeResult(
					$this->placeholderTypeProvider->getPlaceholderDbType($expr),
					$this->placeholderTypeProvider->isPlaceholderNullable($expr),
					null,
					AnalyserKnowledgeBase::createEmpty(),
				);
			case Expr\ExprTypeEnum::TUPLE:
				assert($expr instanceof Expr\Tuple);
				$innerCondition = match ($condition) {
					null => null,
					AnalyserConditionTypeEnum::NULL => AnalyserConditionTypeEnum::NULL,
					default => AnalyserConditionTypeEnum::NOT_NULL,
				};
				$kbCombinineWithAnd = $innerCondition === AnalyserConditionTypeEnum::NOT_NULL;
				$innerFields = array_map(
					fn (Expr\Expr $e) => $this->resolveExprType(
						$e,
						$innerCondition,
						$isNonEmptyAggResultSet,
						$canReferenceGrandParent,
					),
					$expr->expressions,
				);
				$innerTypes = array_map(static fn (ExprTypeResult $f) => $f->type, $innerFields);
				$knowledgeBase = false;

				foreach ($innerFields as $field) {
					if ($knowledgeBase === false) {
						$knowledgeBase = $field->knowledgeBase;

						continue;
					}

					if ($field->knowledgeBase === null) {
						$knowledgeBase = null;

						break;
					}

					assert($knowledgeBase instanceof AnalyserKnowledgeBase);
					$knowledgeBase = $kbCombinineWithAnd
						? $knowledgeBase->and($field->knowledgeBase)
						: $knowledgeBase->or($field->knowledgeBase);
				}

				return new ExprTypeResult(
					new Schema\DbType\TupleType($innerTypes, false),
					$this->isAnyExprNullable($innerFields),
					null,
					$knowledgeBase,
				);
			case Expr\ExprTypeEnum::IN:
				assert($expr instanceof Expr\In);
				[$innerConditionLeft, $innerConditionRight] = match ($condition) {
					AnalyserConditionTypeEnum::FALSY => [
						AnalyserConditionTypeEnum::NOT_NULL,
						AnalyserConditionTypeEnum::NOT_NULL,
					],
					AnalyserConditionTypeEnum::TRUTHY, AnalyserConditionTypeEnum::NOT_NULL => [
						AnalyserConditionTypeEnum::NOT_NULL,
						null,
					],
					AnalyserConditionTypeEnum::NULL => [AnalyserConditionTypeEnum::NULL, null],
					null => [null, null],
				};
				$leftResult = $this->resolveExprType(
					$expr->left,
					$innerConditionLeft,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);
				$rightResult = $this->resolveExprType(
					$expr->right,
					$innerConditionRight,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);
				$rightType = $rightResult->type;

				// $rightType may not be a tuple if it's a subquery (e.g. "1 IN (SELECT 1)")
				if (! $rightType instanceof Schema\DbType\TupleType || $rightType->isFromSubquery) {
					$this->checkSameTypeShape($leftResult->type, $rightType);
				} else {
					foreach ($rightType->types as $rowType) {
						if (! $this->checkSameTypeShape($leftResult->type, $rowType)) {
							break;
						}
					}
				}

				$knowledgeBase = null;

				if ($innerConditionLeft === AnalyserConditionTypeEnum::NOT_NULL || ! $rightResult->isNullable) {
					$knowledgeBase = $leftResult->knowledgeBase;
				}

				if (
					$condition === AnalyserConditionTypeEnum::FALSY
					&& $leftResult->knowledgeBase !== null
					&& $rightResult->knowledgeBase !== null
				) {
					$knowledgeBase = $leftResult->knowledgeBase->and($rightResult->knowledgeBase);
				}

				if ($expr->right instanceof Expr\Subquery) {
					if ($this->hasLimitClause($expr->right->query)) {
						$this->errors[] = AnalyserErrorBuilder::createNoLimitInsideIn();
					}
				}

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					$leftResult->isNullable || $rightResult->isNullable,
					null,
					$knowledgeBase,
				);
			case Expr\ExprTypeEnum::LIKE:
				assert($expr instanceof Expr\Like);
				// TODO: handle $condition
				$expressionResult = $this->resolveExprType(
					$expr->expression,
					null,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);
				$patternResult = $this->resolveExprType(
					$expr->pattern,
					null,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);
				// TODO: check for valid escape char expressions.
				// For example "ESCAPE IF(0, 'a', 'b')" seems to work, but "ESCAPE IF(id = id, 'a', 'b')" doesn't.
				$escapeCharResult = $expr->escapeChar !== null
					? $this->resolveExprType($expr->escapeChar, canReferenceGrandParent: $canReferenceGrandParent)
					: null;

				if (
					in_array(
						Schema\DbType\DbTypeEnum::TUPLE,
						[
							$expressionResult->type::getTypeEnum(),
							$patternResult->type::getTypeEnum(),
							$escapeCharResult?->type::getTypeEnum(),
						],
						true,
					)
				) {
					$this->errors[] = AnalyserErrorBuilder::createInvalidLikeUsageError(
						$expressionResult->type::getTypeEnum(),
						$patternResult->type::getTypeEnum(),
						$escapeCharResult?->type::getTypeEnum(),
					);
				}

				if ($expr->escapeChar instanceof Expr\LiteralString && mb_strlen($expr->escapeChar->value) > 1) {
					$this->errors[] = AnalyserErrorBuilder::createInvalidLikeEscapeMulticharError(
						$expr->escapeChar->value,
					);
				}

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					$expressionResult->isNullable || $patternResult->isNullable,
				);
			case Expr\ExprTypeEnum::FUNCTION_CALL:
				assert($expr instanceof Expr\FunctionCall\FunctionCall);
				$position = 0;
				$arguments = $expr->getArguments();
				$normalizedFunctionName = strtoupper($expr->getFunctionName());
				$resolvedArguments = [];
				$functionInfo = $this->functionInfoRegistry
					->findFunctionInfoByFunctionName($normalizedFunctionName);

				if ($functionInfo === null) {
					$this->errors[] = new AnalyserError(
						"Unhandled function: {$expr->getFunctionName()}",
						AnalyserErrorTypeEnum::MARIA_STAN_UNSUPPORTED_FEATURE,
					);
				}

				$functionType = $functionInfo?->getFunctionType();
				$isAggregateFunction = $functionType === FunctionTypeEnum::AGGREGATE
					|| (
						$functionType === FunctionTypeEnum::AGGREGATE_OR_WINDOW
						&& $expr::getFunctionCallType() !== Expr\FunctionCall\FunctionCallTypeEnum::WINDOW
					);
				$innerConditions = $functionInfo?->getInnerConditions($condition, $arguments) ?? [];

				if ($isAggregateFunction) {
					$this->columnResolver->enterAggregateFunction();
					$this->hasAggregateFunctionCalls = true;
				}

				$bakFieldBehavior = $this->fieldBehavior;

				if (in_array($normalizedFunctionName, ['VALUE', 'VALUES'], true)) {
					$this->fieldBehavior = ColumnResolverFieldBehaviorEnum::ASSIGNMENT;
				}

				foreach ($arguments as $arg) {
					$innerCondition = $innerConditions[$position] ?? null;
					$position++;
					$resolvedArguments[] = $resolvedArg = $this->resolveExprType(
						$arg,
						$innerCondition,
						$isNonEmptyAggResultSet,
						$canReferenceGrandParent,
					);

					if ($resolvedArg->type::getTypeEnum() === Schema\DbType\DbTypeEnum::TUPLE) {
						$this->errors[] = AnalyserErrorBuilder::createInvalidFunctionArgumentError(
							$expr->getFunctionName(),
							$position,
							$resolvedArg->type,
						);
					}
				}

				$this->fieldBehavior = $bakFieldBehavior;

				if ($isAggregateFunction) {
					$this->columnResolver->exitAggregateFunction();
				}

				if ($functionInfo !== null) {
					try {
						return $functionInfo->getReturnType(
							$expr,
							$resolvedArguments,
							$condition,
							$isNonEmptyAggResultSet,
						);
					} catch (AnalyserException $e) {
						$this->errors[] = $e->toAnalyserError();
					}
				}

				return new ExprTypeResult(
					new Schema\DbType\MixedType(),
					true,
				);
			case Expr\ExprTypeEnum::CASE_OP:
				assert($expr instanceof Expr\CaseOp);

				// TODO: handle $condition
				if ($expr->compareValue !== null) {
					$field = $this->resolveExprType(
						$expr->compareValue,
						null,
						$isNonEmptyAggResultSet,
						$canReferenceGrandParent,
					);
					$this->checkNotTuple($field->type);
				}

				$subresults = [];

				foreach ($expr->conditions as $caseCondition) {
					$field = $this->resolveExprType(
						$caseCondition->when,
						canReferenceGrandParent: $canReferenceGrandParent,
					);
					$this->checkNotTuple($field->type);
					$subresults[] = $field = $this->resolveExprType(
						$caseCondition->then,
						null,
						$isNonEmptyAggResultSet,
						$canReferenceGrandParent,
					);
					$this->checkNotTuple($field->type);
				}

				// TODO: CASE with no else may be nullable
				if ($expr->else !== null) {
					$subresults[] = $field = $this->resolveExprType(
						$expr->else,
						null,
						$isNonEmptyAggResultSet,
						$canReferenceGrandParent,
					);
					$this->checkNotTuple($field->type);
				}

				$isNullable = false;
				$type = null;

				foreach ($subresults as $subresult) {
					$isNullable = $isNullable || $subresult->isNullable;

					$type = $type === null
						? $subresult->type
						: FunctionInfoHelper::castToCommonType($type, $subresult->type);
				}

				return new ExprTypeResult($type, $isNullable);
			case Expr\ExprTypeEnum::EXISTS:
				assert($expr instanceof Expr\Exists);
				// TODO: handle $condition
				// TODO: handle $isNonEmptyAggResultSet
				$this->getSubqueryAnalyser($expr->subquery)->analyse();

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					false,
				);
			case Expr\ExprTypeEnum::ASSIGNMENT:
				assert($expr instanceof Expr\Assignment);
				// TODO: handle $condition
				// TODO: handle $isNonEmptyAggResultSet
				$this->runWithDifferentFieldBehavior(
					ColumnResolverFieldBehaviorEnum::ASSIGNMENT,
					fn () => $this->resolveExprType($expr->target, canReferenceGrandParent: $canReferenceGrandParent),
				);
				$value = $this->resolveExprType($expr->expression, canReferenceGrandParent: $canReferenceGrandParent);

				return new ExprTypeResult($value->type, $value->isNullable);
			case Expr\ExprTypeEnum::CAST_TYPE:
				return new ExprTypeResult(
					new Schema\DbType\MixedType(),
					true,
				);
			case Expr\ExprTypeEnum::COLLATE:
				assert($expr instanceof Expr\Collate);
				$subresult = $this->resolveExprType(
					$expr->expression,
					$condition,
					$isNonEmptyAggResultSet,
					$canReferenceGrandParent,
				);

				return new ExprTypeResult(
					new Schema\DbType\VarcharType(),
					$subresult->isNullable,
					null,
					$subresult->knowledgeBase,
				);
			default:
				$this->errors[] = new AnalyserError(
					"Unhandled expression type: {$expr::getExprType()->value}",
					AnalyserErrorTypeEnum::MARIA_STAN_UNSUPPORTED_FEATURE,
				);

				return new ExprTypeResult(
					new Schema\DbType\MixedType(),
					true,
				);
		}
	}

	private function getDefaultFieldNameForExpr(Expr\Expr $expr): string
	{
		switch ($expr::getExprType()) {
			case Expr\ExprTypeEnum::COLUMN:
				assert($expr instanceof Expr\Column);

				return $expr->name;
			case Expr\ExprTypeEnum::LITERAL_NULL:
				return 'NULL';
			case Expr\ExprTypeEnum::LITERAL_STRING:
				assert($expr instanceof Expr\LiteralString);

				return $expr->value;
			default:
				return $this->getNodeContent($expr);
		}
	}

	private function removeUnaryPlusPrefix(Expr\Expr $expr): Expr\Expr
	{
		// MariaDB seems to handle things like +column differently.
		$startExpr = $expr;

		while ($expr::getExprType() === Expr\ExprTypeEnum::UNARY_OP) {
			assert($expr instanceof Expr\UnaryOp);

			if ($expr->operation !== Expr\UnaryOpTypeEnum::PLUS) {
				break;
			}

			$expr = $expr->expression;
		}

		if ($startExpr === $expr) {
			return $startExpr;
		}

		switch ($expr::getExprType()) {
			case Expr\ExprTypeEnum::COLUMN:
			case Expr\ExprTypeEnum::LITERAL_FLOAT:
			case Expr\ExprTypeEnum::LITERAL_NULL:
			case Expr\ExprTypeEnum::LITERAL_INT:
			case Expr\ExprTypeEnum::LITERAL_STRING:
				return $expr;
		}

		return $startExpr;
	}

	private function getNodeContent(Node $node): string
	{
		return $node->getStartPosition()->findSubstringToEndPosition($this->query, $node->getEndPosition());
	}

	private function getSubqueryAnalyser(SelectQuery $subquery, bool $canReferenceGrandParent = false): self
	{
		$other = new self(
			$this->dbReflection,
			$this->functionInfoRegistry,
			$this->placeholderTypeProvider,
			$subquery,
			/** query is used for {@see getNodeContent()} and positions in $subquery are relative to the whole query */
			$this->query,
			new ColumnResolver($this->dbReflection, $this->columnResolver, $canReferenceGrandParent),
		);
		// phpcs:disable SlevomatCodingStandard.PHP.DisallowReference
		$other->errors = &$this->errors;
		$other->positionalPlaceholderCount = &$this->positionalPlaceholderCount;
		$other->referencedTables = &$this->referencedTables;
		$other->referencedTableColumns = &$this->referencedTableColumns;
		// phpcs:enable SlevomatCodingStandard.PHP.DisallowReference

		return $other;
	}

	private function checkSameTypeShape(Schema\DbType\DbType $left, Schema\DbType\DbType $right): bool
	{
		$lt = $left::getTypeEnum();
		$rt = $right::getTypeEnum();

		if ($lt !== Schema\DbType\DbTypeEnum::TUPLE && $rt !== Schema\DbType\DbTypeEnum::TUPLE) {
			return true;
		}

		if ($lt !== Schema\DbType\DbTypeEnum::TUPLE) {
			$this->errors[] = AnalyserErrorBuilder::createInvalidTupleComparisonError($left, $right);

			return false;
		}

		if ($rt !== Schema\DbType\DbTypeEnum::TUPLE) {
			$this->errors[] = AnalyserErrorBuilder::createInvalidTupleComparisonError($left, $right);

			return false;
		}

		assert($left instanceof Schema\DbType\TupleType);
		assert($right instanceof Schema\DbType\TupleType);

		if ($left->typeCount !== $right->typeCount) {
			$this->errors[] = AnalyserErrorBuilder::createInvalidTupleComparisonError($left, $right);

			return false;
		}

		for ($i = 0; $i < $left->typeCount; $i++) {
			if (! $this->checkSameTypeShape($left->types[$i], $right->types[$i])) {
				return false;
			}
		}

		return true;
	}

	private function checkNotTuple(Schema\DbType\DbType $type): void
	{
		if ($type::getTypeEnum() !== Schema\DbType\DbTypeEnum::TUPLE) {
			return;
		}

		assert($type instanceof Schema\DbType\TupleType);
		$this->errors[] = AnalyserErrorBuilder::createInvalidTupleUsageError($type);
	}

	/** @param array<ExprTypeResult> $fields */
	private function isAnyExprNullable(array $fields): bool
	{
		return array_reduce($fields, static fn (bool $carry, ExprTypeResult $f) => $carry || $f->isNullable, false);
	}

	private function getCombinedType(
		Schema\DbType\DbType $left,
		Schema\DbType\DbType $right,
		SelectQueryCombinatorTypeEnum $combinator,
	): Schema\DbType\DbType {
		if (
			$left::getTypeEnum() !== Schema\DbType\DbTypeEnum::ENUM
			|| $right::getTypeEnum() !== Schema\DbType\DbTypeEnum::ENUM
		) {
			return FunctionInfoHelper::castToCommonType($left, $right);
		}

		assert($left instanceof Schema\DbType\EnumType && $right instanceof Schema\DbType\EnumType);
		// TODO: Consider collation
		$combinedCases = match ($combinator) {
			SelectQueryCombinatorTypeEnum::UNION => array_values(
				array_unique(array_merge($left->cases, $right->cases)),
			),
			SelectQueryCombinatorTypeEnum::INTERSECT => array_values(array_intersect($left->cases, $right->cases)),
			// not array_diff: not all right cases may be returned by the right query.
			SelectQueryCombinatorTypeEnum::EXCEPT => $left->cases,
		};

		return new Schema\DbType\EnumType($combinedCases);
	}

	/**
	 * @template T of scalar|null
	 * @param Expr\LiteralExpr<T> $expr
	 */
	private function createKnowledgeBaseForLiteralExpression(
		Expr\LiteralExpr $expr,
		?AnalyserConditionTypeEnum $condition,
	): ?AnalyserKnowledgeBase {
		$value = $expr->getLiteralValue();

		return match ($condition) {
			AnalyserConditionTypeEnum::NULL => AnalyserKnowledgeBase::createFixed($value === null),
			AnalyserConditionTypeEnum::NOT_NULL => AnalyserKnowledgeBase::createFixed($value !== null),
			AnalyserConditionTypeEnum::TRUTHY => AnalyserKnowledgeBase::createFixed(((float) $value) !== 0.0),
			AnalyserConditionTypeEnum::FALSY => AnalyserKnowledgeBase::createFixed(((float) $value) === 0.0),
			null => null,
		};
	}

	/** @throws AnalyserException */
	private function recordColumnReference(?ColumnInfo $columnInfo): void
	{
		if ($columnInfo?->tableType !== ColumnInfoTableTypeEnum::TABLE) {
			return;
		}

		$this->referencedTableColumns[$columnInfo->tableName][$columnInfo->name]
			= new ReferencedSymbol\TableColumn(
				$this->referencedTables[$columnInfo->tableName]
					?? throw new ShouldNotHappenException(
						"Referencing column {$columnInfo->name} of table "
						. "{$columnInfo->tableName} which was not referenced.",
					),
				$columnInfo->name,
			);
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function runWithDifferentFieldBehavior(ColumnResolverFieldBehaviorEnum $fieldBehavior, callable $fn): mixed
	{
		$bak = $this->fieldBehavior;
		$this->fieldBehavior = $fieldBehavior;

		try {
			return $fn();
		} finally {
			$this->fieldBehavior = $bak;
		}
	}

	private function hasLimitClause(SelectQuery $selectQuery): bool
	{
		switch ($selectQuery::getSelectQueryType()) {
			case SelectQueryTypeEnum::SIMPLE:
				assert($selectQuery instanceof SimpleSelectQuery);

				return $selectQuery->limit !== null;
			case SelectQueryTypeEnum::WITH:
				assert($selectQuery instanceof WithSelectQuery);

				return $this->hasLimitClause($selectQuery->selectQuery);
			case SelectQueryTypeEnum::TABLE_VALUE_CONSTRUCTOR:
				return false;
			case SelectQueryTypeEnum::COMBINED:
				assert($selectQuery instanceof CombinedSelectQuery);

				return $selectQuery->limit !== null || $this->hasLimitClause($selectQuery->left)
					|| $this->hasLimitClause($selectQuery->right);
		}
	}

	private function getUnsupportedQueryTypeResult(): AnalyserResult
	{
		// hide it from PHPStan so that it doesn't complain about $this->queryAst::getQueryType()->value being mixed.
		return new AnalyserResult(
			null,
			[
				new AnalyserError(
					"Unsupported query: {$this->queryAst::getQueryType()->value}",
					AnalyserErrorTypeEnum::MARIA_STAN_UNSUPPORTED_FEATURE,
				),
			],
			null,
			null,
			null,
		);
	}
}
