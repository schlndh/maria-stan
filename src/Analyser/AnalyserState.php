<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\Exception\ShouldNotHappenException;
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
use MariaStan\Ast\Query\SelectQuery\WithSelectQuery;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\Subquery;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\Query\TableReference\TableReferenceTypeEnum;
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
use function array_keys;
use function array_map;
use function array_merge;
use function array_reduce;
use function array_values;
use function assert;
use function count;
use function in_array;
use function mb_strlen;
use function min;
use function stripos;
use function strtoupper;

final class AnalyserState
{
	/** @var array<AnalyserError> */
	private array $errors = [];
	private ColumnResolver $columnResolver;
	private int $positionalPlaceholderCount = 0;

	/** @var array<string, ReferencedSymbol\Table> name => table */
	private array $referencedTables = [];

	/** @var array<string, array<string, ReferencedSymbol\TableColumn>> table name => column name => column */
	private array $referencedTableColumns = [];

	public function __construct(
		private readonly DbReflection $dbReflection,
		private readonly FunctionInfoRegistry $functionInfoRegistry,
		private readonly Query $queryAst,
		private readonly string $query,
		?ColumnResolver $columnResolver = null,
	) {
		$this->columnResolver = $columnResolver ?? new ColumnResolver($this->dbReflection);
	}

	/** @throws AnalyserException */
	public function analyse(): AnalyserResult
	{
		switch ($this->queryAst::getQueryType()) {
			case QueryTypeEnum::SELECT:
				assert($this->queryAst instanceof SelectQuery);
				$fields = $this->dispatchAnalyseSelectQuery($this->queryAst);
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
				return new AnalyserResult(
					null,
					[new AnalyserError("Unsupported query: {$this->queryAst::getQueryType()->value}")],
					null,
					null,
				);
		}

		$referencedSymbols = array_values($this->referencedTables);

		foreach ($this->referencedTableColumns as $columns) {
			$referencedSymbols = array_merge($referencedSymbols, $columns);
		}

		return new AnalyserResult($fields, $this->errors, $this->positionalPlaceholderCount, $referencedSymbols);
	}

	/**
	 * @return array<QueryResultField>
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
			default:
				$this->errors[] = new AnalyserError("Unhandled SELECT type {$select::getSelectQueryType()->value}");

				return [];
		}
	}

	/**
	 * @return array<QueryResultField>
	 * @throws AnalyserException
	 */
	private function analyseWithSelectQuery(WithSelectQuery $select): array
	{
		if ($select->allowRecursive) {
			$this->errors[] = new AnalyserError(
				"WITH RECURSIVE is not currently supported. There may be false positives!",
			);
		}

		foreach ($select->commonTableExpressions as $cte) {
			$subqueryFields = $this->getSubqueryAnalyser($cte->subquery)->analyse()->resultFields ?? [];

			if (count($subqueryFields) === 0) {
				$this->errors[] = new AnalyserError("CTE {$cte->name} doesn't have any columns.");

				continue;
			}

			if ($cte->columnList !== null) {
				$fieldCount = count($subqueryFields);
				$columnCount = count($cte->columnList);

				if ($fieldCount !== $columnCount) {
					$this->errors[] = new AnalyserError(
						AnalyserErrorMessageBuilder::createDifferentNumberOfWithColumnsErrorMessage(
							$columnCount,
							$fieldCount,
						),
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
				$this->errors[] = new AnalyserError($e->getMessage());
			}
		}

		return $this->dispatchAnalyseSelectQuery($select->selectQuery);
	}

	/**
	 * @return array<QueryResultField>
	 * @throws AnalyserException
	 */
	private function analyseCombinedSelectQuery(CombinedSelectQuery $select): array
	{
		$leftFields = $this->getSubqueryAnalyser($select->left)->analyse()->resultFields;

		if ($leftFields === null) {
			throw new AnalyserException('Subquery fields are null, this should not happen.');
		}

		$rightFields = $this->getSubqueryAnalyser($select->right)->analyse()->resultFields;

		if ($rightFields === null) {
			throw new AnalyserException('Subquery fields are null, this should not happen.');
		}

		$countLeft = count($leftFields);
		$countRight = count($rightFields);
		$commonCount = $countLeft;

		if ($countLeft !== $countRight) {
			$commonCount = min($countLeft, $countRight);
			$this->errors[] = new AnalyserError(
				AnalyserErrorMessageBuilder::createDifferentNumberOfColumnsErrorMessage($countLeft, $countRight),
			);
		}

		$fields = [];
		$i = 0;

		for (; $i < $commonCount; $i++) {
			$lf = $leftFields[$i];
			$rf = $rightFields[$i];
			$combinedType = $this->getCombinedType($lf->exprType->type, $rf->exprType->type);
			$fields[] = new QueryResultField(
				$lf->name,
				new ExprTypeResult($combinedType, $lf->exprType->isNullable || $rf->exprType->isNullable),
			);
		}

		unset($leftFields, $rightFields);
		$this->columnResolver->registerFieldList($fields);
		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::ORDER_BY);

		foreach ($select->orderBy?->expressions ?? [] as $orderByExpr) {
			$this->resolveExprType($orderByExpr->expr);
		}

		if ($select->limit?->count !== null) {
			$this->resolveExprType($select->limit->count);
		}

		if ($select->limit?->offset !== null) {
			$this->resolveExprType($select->limit->offset);
		}

		return $fields;
	}

	/**
	 * @return array<QueryResultField>
	 * @throws AnalyserException
	 */
	private function analyseSingleSelectQuery(SimpleSelectQuery $select): array
	{
		$fromClause = $select->from;

		if ($fromClause !== null) {
			try {
				$this->columnResolver = $this->analyseTableReference($fromClause, clone $this->columnResolver)[1];
			} catch (AnalyserException | DbReflectionException $e) {
				$this->errors[] = new AnalyserError($e->getMessage());
			}
		}

		if ($select->where) {
			$whereResult = $this->resolveExprType($select->where, AnalyserConditionTypeEnum::TRUTHY);
			$this->columnResolver->addKnowledge($whereResult->knowledgeBase);
		}

		$fields = [];
		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::FIELD_LIST);

		foreach ($select->select as $selectExpr) {
			switch ($selectExpr::getSelectExprType()) {
				case SelectExprTypeEnum::REGULAR_EXPR:
					assert($selectExpr instanceof RegularExpr);
					$expr = $this->removeUnaryPlusPrefix($selectExpr->expr);
					$resolvedExpr = $this->resolveExprType($expr);
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
					$allFields = $this->columnResolver->resolveAllColumns($selectExpr->tableName);

					foreach ($allFields as $field) {
						$this->columnResolver->registerField($field, true);
						$this->recordColumnReference($field->exprType->column);
					}

					$fields = array_merge($fields, $allFields);
					unset($allFields);
					break;
			}
		}

		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::GROUP_BY);

		foreach ($select->groupBy?->expressions ?? [] as $groupByExpr) {
			$exprType = $this->resolveExprType($groupByExpr->expr);

			if ($exprType->column === null) {
				continue;
			}

			$this->columnResolver->registerGroupByColumn($exprType->column);
		}

		if ($select->having) {
			$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::HAVING);
			$this->resolveExprType($select->having);
		}

		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::ORDER_BY);

		foreach ($select->orderBy?->expressions ?? [] as $orderByExpr) {
			$this->resolveExprType($orderByExpr->expr);
		}

		if ($select->limit?->count !== null) {
			$this->resolveExprType($select->limit->count);
		}

		if ($select->limit?->offset !== null) {
			$this->resolveExprType($select->limit->offset);
		}

		return $fields;
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
					$tableType = $columnResolver->registerTable($fromClause->name, $fromClause->alias);

					if ($tableType === ColumnInfoTableTypeEnum::TABLE) {
						$this->referencedTables[$fromClause->name] ??= new ReferencedSymbol\Table($fromClause->name);
					}
				} catch (AnalyserException $e) {
					$this->errors[] = new AnalyserError($e->getMessage());
				}

				return [[$fromClause->alias ?? $fromClause->name], $columnResolver];
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
					$this->errors[] = new AnalyserError($e->getMessage());
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

				match (true) {
					$fromClause->joinCondition instanceof Expr\Expr
						=> $this->resolveExprType($fromClause->joinCondition),
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
				}

				return [array_merge($leftTables, $rightTables), $columnResolver];
		}

		return [[], $columnResolver];
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
				if ($this->columnResolver->hasTableForDelete($table)) {
					continue;
				}

				$this->errors[] = new AnalyserError(
					AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage($table),
				);
			}
		} catch (AnalyserException | DbReflectionException $e) {
			$this->errors[] = new AnalyserError($e->getMessage());
		}

		foreach ($this->columnResolver->getCollidingSubqueryAndTableAliases() as $alias) {
			$this->errors[] = new AnalyserError(
				AnalyserErrorMessageBuilder::createNotUniqueTableAliasErrorMessage($alias),
			);
		}

		if ($query->where !== null) {
			$this->resolveExprType($query->where);
		}

		foreach ($query->orderBy?->expressions ?? [] as $orderByExpr) {
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
			$this->errors[] = new AnalyserError($e->getMessage());
		}

		foreach ($query->assignments as $assignment) {
			$this->resolveExprType($assignment);
		}

		if ($query->where !== null) {
			$this->resolveExprType($query->where);
		}

		foreach ($query->orderBy?->expressions ?? [] as $orderByExpr) {
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
		$tableReferenceNode = new Table($mockPosition, $mockPosition, $query->tableName);

		try {
			$this->columnResolver = $this->analyseTableReference($tableReferenceNode, clone $this->columnResolver)[1];
		} catch (AnalyserException | DbReflectionException $e) {
			$this->errors[] = new AnalyserError($e->getMessage());
		}

		$tableSchema = $this->columnResolver->findTableSchema($query->tableName);
		$setColumnNames = [];

		switch ($query->insertBody::getInsertBodyType()) {
			case InsertBodyTypeEnum::SELECT:
				assert($query->insertBody instanceof SelectInsertBody);

				foreach ($query->insertBody->columnList ?? [] as $column) {
					$this->resolveExprType($column);
				}

				$selectResult = $this->getSubqueryAnalyser($query->insertBody->selectQuery)->analyse()->resultFields
					?? [];

				// if $selectResult is empty (e.g. missing table) then there should already be an error reported.
				if ($tableSchema === null || count($selectResult) === 0) {
					break;
				}

				$setColumnNames = $query->insertBody->columnList !== null
					? array_map(static fn (Expr\Column $c) => $c->name, $query->insertBody->columnList)
					: array_keys($tableSchema->columns);
				$expectedCount = count($setColumnNames);

				if ($expectedCount === count($selectResult)) {
					break;
				}

				$this->errors[] = new AnalyserError(
					AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(
						$expectedCount,
						count($selectResult),
					),
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

					$this->errors[] = new AnalyserError(
						AnalyserErrorMessageBuilder::createMismatchedInsertColumnCountErrorMessage(
							$expectedCount,
							count($tuple),
						),
					);

					// Report only 1 mismatch. The mismatches are probably all going to be the same.
					break;
				}

				break;
		}

		if ($query instanceof InsertQuery) {
			foreach ($query->onDuplicateKeyUpdate ?? [] as $assignment) {
				$this->resolveExprType($assignment);
			}
		}

		$setColumNamesMap = array_fill_keys($setColumnNames, 1);

		foreach ($tableSchema?->columns ?? [] as $name => $column) {
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

			$this->errors[] = new AnalyserError(
				AnalyserErrorMessageBuilder::createMissingValueForColumnErrorMessage($name),
			);
		}

		// TODO: INSERT ... ON DUPLICATE KEY
		// TODO: INSERT ... RETURNING
		return [];
	}

	/** @throws AnalyserException */
	private function analyseTruncateQuery(TruncateQuery $query): void
	{
		try {
			$this->dbReflection->findTableSchema($query->tableName);
		} catch (DbReflectionException $e) {
			$this->errors[] = new AnalyserError($e->getMessage());
		}
	}

	/** @throws AnalyserException */
	private function resolveExprType(Expr\Expr $expr, ?AnalyserConditionTypeEnum $condition = null): ExprTypeResult
	{
		// TODO: handle all expression types
		switch ($expr::getExprType()) {
			case Expr\ExprTypeEnum::COLUMN:
				assert($expr instanceof Expr\Column);

				try {
					$resolvedColumn = $this->columnResolver->resolveColumn($expr->name, $expr->tableName, $condition);

					foreach ($resolvedColumn->warnings as $warning) {
						$this->errors[] = new AnalyserError($warning);
					}

					$this->recordColumnReference($resolvedColumn->result->column);

					return $resolvedColumn->result;
				} catch (AnalyserException $e) {
					$this->errors[] = new AnalyserError($e->getMessage());
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
				$timeQuantityResult = $this->resolveExprType($expr->timeQuantity, $innerCondition);

				return new ExprTypeResult(
					new Schema\DbType\DateTimeType(),
					$timeQuantityResult->isNullable,
					null,
					$timeQuantityResult->knowledgeBase,
				);
			case Expr\ExprTypeEnum::UNARY_OP:
				assert($expr instanceof Expr\UnaryOp);
				$innerCondition = $condition;

				// TODO: handle $condition for +, -, ~, BINARY
				if ($innerCondition !== null && $expr->operation === Expr\UnaryOpTypeEnum::LOGIC_NOT) {
					$innerCondition = match ($innerCondition) {
						AnalyserConditionTypeEnum::TRUTHY => AnalyserConditionTypeEnum::FALSY,
						AnalyserConditionTypeEnum::FALSY => AnalyserConditionTypeEnum::TRUTHY,
						// NULL(NOT(a)) <=> NULL(a), NOT_NULL(NOT(a)) <=> NOT_NULL(a)
						default => $innerCondition,
					};
				}

				$resolvedInnerExpr = $this->resolveExprType($expr->expression, $innerCondition);

				$type = match ($expr->operation) {
					Expr\UnaryOpTypeEnum::PLUS => $resolvedInnerExpr->type,
					Expr\UnaryOpTypeEnum::MINUS => match ($resolvedInnerExpr->type::getTypeEnum()) {
						Schema\DbType\DbTypeEnum::INT, Schema\DbType\DbTypeEnum::DECIMAL => $resolvedInnerExpr->type,
						Schema\DbType\DbTypeEnum::DATETIME => new Schema\DbType\DecimalType(),
						default => new Schema\DbType\FloatType(),
					},
					Expr\UnaryOpTypeEnum::BINARY => new Schema\DbType\VarcharType(),
					default => new Schema\DbType\IntType(),
				};

				return new ExprTypeResult(
					$type,
					$resolvedInnerExpr->isNullable,
					null,
					$resolvedInnerExpr->knowledgeBase,
				);
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
				];
				$divOperators = [
					Expr\BinaryOpTypeEnum::DIVISION,
					Expr\BinaryOpTypeEnum::INT_DIVISION,
					Expr\BinaryOpTypeEnum::MODULO,
				];

				// TODO: handle $condition for XOR, <=>, REGEXP, |, &, <<, >>, ^
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
				} elseif (
					in_array($expr->operation, $nullUnsafeComparisonOperators, true)
					|| in_array($expr->operation, $nullUnsafeArithmeticOperators, true)
				) {
					$innerConditionLeft = $innerConditionRight = match ($condition) {
						AnalyserConditionTypeEnum::NULL => AnalyserConditionTypeEnum::NULL,
						// For now, we can only determine that none of the operands can be NULL.
						default => AnalyserConditionTypeEnum::NOT_NULL,
					};
					$kbCombinineWithAnd = $innerConditionLeft === AnalyserConditionTypeEnum::NOT_NULL;
				} elseif (in_array($expr->operation, $divOperators, true)) {
					[$innerConditionLeft, $innerConditionRight] = match ($condition) {
						// We'd have to consider both FALSY and NULL for right side.
						AnalyserConditionTypeEnum::NULL => [null, null],
						// For now, we can only determine that none of the operands can be NULL.
						default => [AnalyserConditionTypeEnum::NOT_NULL, AnalyserConditionTypeEnum::TRUTHY],
					};
					$kbCombinineWithAnd = true;
				}

				$leftResult = $this->resolveExprType($expr->left, $innerConditionLeft);
				$rightResult = $this->resolveExprType($expr->right, $innerConditionRight);
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

					if (isset($typesInvolved[Schema\DbType\DbTypeEnum::TUPLE->value])) {
						if (
							! in_array(
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
							)
						) {
							$this->errors[] = new AnalyserError(
								AnalyserErrorMessageBuilder::createInvalidBinaryOpUsageErrorMessage(
									$expr->operation,
									$lt,
									$rt,
								),
							);
							$type = new Schema\DbType\MixedType();
						} else {
							$this->checkSameTypeShape($leftResult->type, $rightResult->type);
							$type = new Schema\DbType\IntType();
						}
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::NULL->value])) {
						$type = new Schema\DbType\NullType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::MIXED->value])) {
						$type = new Schema\DbType\MixedType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::VARCHAR->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
							? new Schema\DbType\IntType()
							: new Schema\DbType\FloatType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::FLOAT->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
							? new Schema\DbType\IntType()
							: new Schema\DbType\FloatType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::DECIMAL->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
							? new Schema\DbType\IntType()
							: new Schema\DbType\DecimalType();
					} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::INT->value])) {
						$type = $expr->operation === Expr\BinaryOpTypeEnum::DIVISION
							? new Schema\DbType\DecimalType()
							: new Schema\DbType\IntType();
					}

					$isNullable = $leftResult->isNullable || $rightResult->isNullable
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

				return new ExprTypeResult($type, $isNullable, null, $knowledgeBase);
			case Expr\ExprTypeEnum::SUBQUERY:
				assert($expr instanceof Expr\Subquery);
				// TODO: handle $condition
				$subqueryAnalyser = $this->getSubqueryAnalyser($expr->query);
				$result = $subqueryAnalyser->analyse();

				if ($result->resultFields === null) {
					return new ExprTypeResult(
						new Schema\DbType\MixedType(),
						// TODO: Change it to false if we can statically determine that the query will always return
						// a result: e.g. SELECT 1
						true,
					);
				}

				if (count($result->resultFields) === 1) {
					return new ExprTypeResult(
						$result->resultFields[0]->exprType->type,
						// TODO: Change it to false if we can statically determine that the query will always return
						// a result: e.g. SELECT 1
						// TODO: Fix this for "x IN (SELECT ...)".
						true,
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
				$exprResult = $this->resolveExprType($expr->expression, $innerCondition);

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
					array_map($this->resolveExprType(...), [$expr->expression, $expr->min, $expr->max]),
					static fn (bool $isNullable, ExprTypeResult $f) => $isNullable || $f->isNullable,
					false,
				);

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					$isNullable,
				);
			case Expr\ExprTypeEnum::PLACEHOLDER:
				// TODO: handle $condition
				$this->positionalPlaceholderCount++;

				// TODO: is VARCHAR just a side-effect of the way mysqli binds the parameters?
				return new ExprTypeResult(new Schema\DbType\VarcharType(), true);
			case Expr\ExprTypeEnum::TUPLE:
				assert($expr instanceof Expr\Tuple);
				$innerCondition = match ($condition) {
					null => null,
					AnalyserConditionTypeEnum::NULL => AnalyserConditionTypeEnum::NULL,
					default => AnalyserConditionTypeEnum::NOT_NULL,
				};
				$kbCombinineWithAnd = $innerCondition === AnalyserConditionTypeEnum::NOT_NULL;
				$innerFields = array_map(
					fn (Expr\Expr $e) => $this->resolveExprType($e, $innerCondition),
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
				$leftResult = $this->resolveExprType($expr->left, $innerConditionLeft);
				$rightResult = $this->resolveExprType($expr->right, $innerConditionRight);
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

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					$leftResult->isNullable || $rightResult->isNullable,
					null,
					$knowledgeBase,
				);
			case Expr\ExprTypeEnum::LIKE:
				assert($expr instanceof Expr\Like);
				// TODO: handle $condition
				$expressionResult = $this->resolveExprType($expr->expression);
				$patternResult = $this->resolveExprType($expr->pattern);
				// TODO: check for valid escape char expressions.
				// For example "ESCAPE IF(0, 'a', 'b')" seems to work, but "ESCAPE IF(id = id, 'a', 'b')" doesn't.
				$escapeCharResult = $expr->escapeChar !== null
					? $this->resolveExprType($expr->escapeChar)
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
					$this->errors[] = new AnalyserError(
						AnalyserErrorMessageBuilder::createInvalidLikeUsageErrorMessage(
							$expressionResult->type::getTypeEnum(),
							$patternResult->type::getTypeEnum(),
							$escapeCharResult?->type::getTypeEnum(),
						),
					);
				}

				if ($expr->escapeChar instanceof Expr\LiteralString && mb_strlen($expr->escapeChar->value) > 1) {
					$this->errors[] = new AnalyserError(
						AnalyserErrorMessageBuilder::createInvalidLikeEscapeMulticharErrorMessage(
							$expr->escapeChar->value,
						),
					);
				}

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					$expressionResult->isNullable || $patternResult->isNullable,
				);
			case Expr\ExprTypeEnum::FUNCTION_CALL:
				assert($expr instanceof Expr\FunctionCall\FunctionCall);
				// TODO: handle $condition
				$position = 0;
				$arguments = $expr->getArguments();
				$normalizedFunctionName = strtoupper($expr->getFunctionName());
				$resolvedArguments = [];
				$functionInfo = $this->functionInfoRegistry
					->findFunctionInfoByFunctionName($normalizedFunctionName);

				if ($functionInfo === null) {
					$this->errors[] = new AnalyserError("Unhandled function: {$expr->getFunctionName()}");
				}

				$functionType = $functionInfo?->getFunctionType();
				$isAggregateFunction = $functionType === FunctionTypeEnum::AGGREGATE
					|| (
						$functionType === FunctionTypeEnum::AGGREGATE_OR_WINDOW
						&& $expr::getFunctionCallType() !== Expr\FunctionCall\FunctionCallTypeEnum::WINDOW
					);

				if ($isAggregateFunction) {
					$this->columnResolver->enterAggregateFunction();
				}

				foreach ($arguments as $arg) {
					$position++;
					$resolvedArguments[] = $resolvedArg = $this->resolveExprType($arg);

					if ($resolvedArg->type::getTypeEnum() === Schema\DbType\DbTypeEnum::TUPLE) {
						$this->errors[] = new AnalyserError(
							AnalyserErrorMessageBuilder::createInvalidFunctionArgumentErrorMessage(
								$expr->getFunctionName(),
								$position,
								$resolvedArg->type,
							),
						);
					}
				}

				if ($isAggregateFunction) {
					$this->columnResolver->exitAggregateFunction();
				}

				if ($functionInfo !== null) {
					try {
						return $functionInfo->getReturnType($expr, $resolvedArguments);
					} catch (AnalyserException $e) {
						$this->errors[] = new AnalyserError($e->getMessage());
					}
				}

				return new ExprTypeResult(
					new Schema\DbType\MixedType(),
					true,
				);
			case Expr\ExprTypeEnum::CASE_OP:
				assert($expr instanceof Expr\CaseOp);

				// TODO: handle $condition
				if ($expr->compareValue) {
					$field = $this->resolveExprType($expr->compareValue);
					$this->checkNotTuple($field->type);
				}

				$subresults = [];

				foreach ($expr->conditions as $condition) {
					$field = $this->resolveExprType($condition->when);
					$this->checkNotTuple($field->type);
					$subresults[] = $field = $this->resolveExprType($condition->then);
					$this->checkNotTuple($field->type);
				}

				if ($expr->else) {
					$subresults[] = $field = $this->resolveExprType($expr->else);
					$this->checkNotTuple($field->type);
				}

				$isNullable = false;
				$type = null;

				foreach ($subresults as $subresult) {
					$isNullable = $isNullable || $subresult->isNullable;

					$type = $type === null
						? $subresult->type
						: $this->getCombinedType($type, $subresult->type);
				}

				return new ExprTypeResult($type, $isNullable);
			case Expr\ExprTypeEnum::EXISTS:
				assert($expr instanceof Expr\Exists);
				// TODO: handle $condition
				$this->getSubqueryAnalyser($expr->subquery)->analyse();

				return new ExprTypeResult(
					new Schema\DbType\IntType(),
					false,
				);
			case Expr\ExprTypeEnum::ASSIGNMENT:
				assert($expr instanceof Expr\Assignment);
				// TODO: handle $condition
				$this->resolveExprType($expr->target);
				$value = $this->resolveExprType($expr->expression);

				return new ExprTypeResult($value->type, $value->isNullable);
			case Expr\ExprTypeEnum::CAST_TYPE:
				return new ExprTypeResult(
					new Schema\DbType\MixedType(),
					true,
				);
			default:
				$this->errors[] = new AnalyserError("Unhandled expression type: {$expr::getExprType()->value}");

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

				return $expr->firstConcatPart;
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

	private function getSubqueryAnalyser(SelectQuery $subquery): self
	{
		$other = new self(
			$this->dbReflection,
			$this->functionInfoRegistry,
			$subquery,
			/** query is used for {@see getNodeContent()} and positions in $subquery are relative to the whole query */
			$this->query,
			new ColumnResolver($this->dbReflection, $this->columnResolver),
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
			$this->errors[] = new AnalyserError(
				AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage($left, $right),
			);

			return false;
		}

		if ($rt !== Schema\DbType\DbTypeEnum::TUPLE) {
			$this->errors[] = new AnalyserError(
				AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage($left, $right),
			);

			return false;
		}

		assert($left instanceof Schema\DbType\TupleType);
		assert($right instanceof Schema\DbType\TupleType);

		if ($left->typeCount !== $right->typeCount) {
			$this->errors[] = new AnalyserError(
				AnalyserErrorMessageBuilder::createInvalidTupleComparisonErrorMessage($left, $right),
			);

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
		$this->errors[] = new AnalyserError(
			AnalyserErrorMessageBuilder::createInvalidTupleUsageErrorMessage($type),
		);
	}

	/** @param array<ExprTypeResult> $fields */
	private function isAnyExprNullable(array $fields): bool
	{
		return array_reduce($fields, static fn (bool $carry, ExprTypeResult $f) => $carry || $f->isNullable, false);
	}

	private function getCombinedType(Schema\DbType\DbType $left, Schema\DbType\DbType $right): Schema\DbType\DbType
	{
		return FunctionInfoHelper::castToCommonType($left, $right);
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
}
