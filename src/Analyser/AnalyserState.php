<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Ast\Expr;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\InsertBody\InsertBodyTypeEnum;
use MariaStan\Ast\Query\InsertBody\SelectInsertBody;
use MariaStan\Ast\Query\InsertBody\SetInsertBody;
use MariaStan\Ast\Query\InsertBody\ValuesInsertBody;
use MariaStan\Ast\Query\InsertQuery;
use MariaStan\Ast\Query\Query;
use MariaStan\Ast\Query\QueryTypeEnum;
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
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExprTypeEnum;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\Position;
use MariaStan\Schema;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reduce;
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

	public function __construct(
		private readonly MariaDbOnlineDbReflection $dbReflection,
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
				assert($this->queryAst instanceof InsertQuery);
				$fields = $this->analyseInsertQuery($this->queryAst);
				break;
			default:
				return new AnalyserResult(
					null,
					[new AnalyserError("Unsupported query: {$this->queryAst::getQueryType()->value}")],
					null,
				);
		}

		return new AnalyserResult($fields, $this->errors, $this->positionalPlaceholderCount);
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
			$combinedType = $this->getCombinedType($lf->type, $rf->type);
			$fields[] = new QueryResultField($lf->name, $combinedType, $lf->isNullable || $rf->isNullable);
		}

		unset($leftFields, $rightFields);
		$this->columnResolver->registerFieldList($fields);
		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::HAVING);

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
			$this->resolveExprType($select->where);
		}

		$fields = [];
		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::FIELD_LIST);

		foreach ($select->select as $selectExpr) {
			switch ($selectExpr::getSelectExprType()) {
				case SelectExprTypeEnum::REGULAR_EXPR:
					assert($selectExpr instanceof RegularExpr);
					$resolvedField = $this->resolveExprType($selectExpr->expr);

					if ($selectExpr->alias !== null && $selectExpr->alias !== $resolvedField->name) {
						$resolvedField = $resolvedField->getRenamed($selectExpr->alias);
					}

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
					}

					$fields = array_merge($fields, $allFields);
					unset($allFields);
					break;
			}
		}

		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::GROUP_BY);

		foreach ($select->groupBy?->expressions ?? [] as $groupByExpr) {
			$this->resolveExprType($groupByExpr->expr);
		}

		$this->columnResolver->setFieldListBehavior(ColumnResolverFieldBehaviorEnum::HAVING);

		if ($select->having) {
			$this->resolveExprType($select->having);
		}

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
					$columnResolver->registerTable($fromClause->name, $fromClause->alias);
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
	private function analyseInsertQuery(InsertQuery $insert): array
	{
		static $mockPosition = null;
		$mockPosition ??= new Position(0, 0, 0);
		$tableReferenceNode = new Table($mockPosition, $mockPosition, $insert->tableName);

		try {
			$this->columnResolver = $this->analyseTableReference($tableReferenceNode, clone $this->columnResolver)[1];
		} catch (AnalyserException | DbReflectionException $e) {
			$this->errors[] = new AnalyserError($e->getMessage());
		}

		$tableSchema = $this->columnResolver->findTableSchema($insert->tableName);
		$setColumnNames = [];

		switch ($insert->insertBody::getInsertBodyType()) {
			case InsertBodyTypeEnum::SELECT:
				assert($insert->insertBody instanceof SelectInsertBody);

				foreach ($insert->insertBody->columnList ?? [] as $column) {
					$this->resolveExprType($column);
				}

				$selectResult = $this->getSubqueryAnalyser($insert->insertBody->selectQuery)->analyse()->resultFields
					?? [];

				// if $selectResult is empty (e.g. missing table) then there should already be an error reported.
				if ($tableSchema === null || count($selectResult) === 0) {
					break;
				}

				$setColumnNames = $insert->insertBody->columnList !== null
					? array_map(static fn (Expr\Column $c) => $c->name, $insert->insertBody->columnList)
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
				assert($insert->insertBody instanceof SetInsertBody);

				foreach ($insert->insertBody->assignments as $expr) {
					$setColumnNames[] = $expr->target->name;
					$this->resolveExprType($expr);
				}

				break;
			case InsertBodyTypeEnum::VALUES:
				assert($insert->insertBody instanceof ValuesInsertBody);

				foreach ($insert->insertBody->columnList ?? [] as $column) {
					$this->resolveExprType($column);
				}

				foreach ($insert->insertBody->values as $tuple) {
					foreach ($tuple as $expr) {
						$this->resolveExprType($expr);
					}
				}

				if ($tableSchema === null) {
					break;
				}

				$setColumnNames = $insert->insertBody->columnList !== null
					? array_map(static fn (Expr\Column $c) => $c->name, $insert->insertBody->columnList)
					: array_keys($tableSchema->columns);
				$expectedCount = count($setColumnNames);

				foreach ($insert->insertBody->values as $tuple) {
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

		foreach ($insert->onDuplicateKeyUpdate ?? [] as $assignment) {
			$this->resolveExprType($assignment);
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

		// TODO: check if fields without default value are missing
		// TODO: INSERT ... ON DUPLICATE KEY
		// TODO: INSERT ... RETURNING
		return [];
	}

	/** @throws AnalyserException */
	private function resolveExprType(Expr\Expr $expr): QueryResultField
	{
		// TODO: handle all expression types
		switch ($expr::getExprType()) {
			case Expr\ExprTypeEnum::COLUMN:
				assert($expr instanceof Expr\Column);

				try {
					return $this->columnResolver->resolveColumn($expr->name, $expr->tableName);
				} catch (AnalyserException $e) {
					$this->errors[] = new AnalyserError($e->getMessage());
				}

				return new QueryResultField($expr->name, new Schema\DbType\MixedType(), true);
			case Expr\ExprTypeEnum::LITERAL_INT:
				assert($expr instanceof Expr\LiteralInt);

				return new QueryResultField($this->getNodeContent($expr), new Schema\DbType\IntType(), false);
			case Expr\ExprTypeEnum::LITERAL_FLOAT:
				assert($expr instanceof Expr\LiteralFloat);
				$content = $this->getNodeContent($expr);
				$isExponentNotation = stripos($content, 'e') !== false;

				return new QueryResultField(
					$content,
					$isExponentNotation
						? new Schema\DbType\FloatType()
						: new Schema\DbType\DecimalType(),
					false,
				);
			case Expr\ExprTypeEnum::LITERAL_NULL:
				return new QueryResultField('NULL', new Schema\DbType\NullType(), true);
			case Expr\ExprTypeEnum::LITERAL_STRING:
				assert($expr instanceof Expr\LiteralString);

				return new QueryResultField($expr->firstConcatPart, new Schema\DbType\VarcharType(), false);
			case Expr\ExprTypeEnum::INTERVAL:
				assert($expr instanceof Expr\Interval);
				$timeQuantityResult = $this->resolveExprType($expr->timeQuantity);

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\DateTimeType(),
					$timeQuantityResult->isNullable,
				);
			case Expr\ExprTypeEnum::UNARY_OP:
				assert($expr instanceof Expr\UnaryOp);
				$resolvedInnerExpr = $this->resolveExprType($expr->expression);

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

				return new QueryResultField(
					// It seems that MariaDB generally omits the +.
					// TODO: investigate it more and fix stuff like "SELECT +(SELECT 1)"
					$expr->operation !== Expr\UnaryOpTypeEnum::PLUS
						? $this->getNodeContent($expr)
						: $resolvedInnerExpr->name,
					$type,
					$resolvedInnerExpr->isNullable,
				);
			case Expr\ExprTypeEnum::BINARY_OP:
				assert($expr instanceof Expr\BinaryOp);
				$leftResult = $this->resolveExprType($expr->left);

				if (
					(
						$expr->operation === Expr\BinaryOpTypeEnum::PLUS
						|| $expr->operation === Expr\BinaryOpTypeEnum::MINUS
					) && $expr->right::getExprType() === Expr\ExprTypeEnum::INTERVAL
				) {
					$intervalExpr = $expr->right;
					$intervalResult = $this->resolveExprType($intervalExpr);

					return new QueryResultField(
						$this->getNodeContent($expr),
						new Schema\DbType\DateTimeType(),
						$leftResult->isNullable
							|| $intervalResult->isNullable
							|| $leftResult->type::getTypeEnum() !== Schema\DbType\DbTypeEnum::DATETIME,
					);
				}

				$rightResult = $this->resolveExprType($expr->right);
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
				} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::DECIMAL->value])) {
					$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
						? new Schema\DbType\IntType()
						: new Schema\DbType\DecimalType();
				} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::FLOAT->value])) {
					$type = $expr->operation === Expr\BinaryOpTypeEnum::INT_DIVISION
						? new Schema\DbType\IntType()
						: new Schema\DbType\FloatType();
				} elseif (isset($typesInvolved[Schema\DbType\DbTypeEnum::INT->value])) {
					$type = $expr->operation === Expr\BinaryOpTypeEnum::DIVISION
						? new Schema\DbType\DecimalType()
						: new Schema\DbType\IntType();
				}

				// TODO: Analyze the rest of the operators
				$type ??= new Schema\DbType\FloatType();

				return new QueryResultField(
					$this->getNodeContent($expr),
					$type,
					$leftResult->isNullable
						|| $rightResult->isNullable
						// It can be division by 0 in which case MariaDB returns null.
						|| in_array($expr->operation, [
							Expr\BinaryOpTypeEnum::DIVISION,
							Expr\BinaryOpTypeEnum::INT_DIVISION,
							Expr\BinaryOpTypeEnum::MODULO,
						], true),
				);
			case Expr\ExprTypeEnum::SUBQUERY:
				assert($expr instanceof Expr\Subquery);
				$subqueryAnalyser = $this->getSubqueryAnalyser($expr->query);
				$result = $subqueryAnalyser->analyse();

				if ($result->resultFields === null) {
					return new QueryResultField(
						$this->getNodeContent($expr),
						new Schema\DbType\MixedType(),
						// TODO: Change it to false if we can statically determine that the query will always return
						// a result: e.g. SELECT 1
						true,
					);
				}

				if (count($result->resultFields) === 1) {
					return new QueryResultField(
						$this->getNodeContent($expr),
						$result->resultFields[0]->type,
						// TODO: Change it to false if we can statically determine that the query will always return
						// a result: e.g. SELECT 1
						true,
					);
				}

				$innerTypes = array_map(static fn (QueryResultField $f) => $f->type, $result->resultFields);

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\TupleType($innerTypes, true),
					$this->isAnyFieldNullable($result->resultFields),
				);
			case Expr\ExprTypeEnum::IS:
				assert($expr instanceof Expr\Is);
				// Make sure there are no errors on the left of IS.
				$this->resolveExprType($expr->expression);

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\IntType(),
					false,
				);
			case Expr\ExprTypeEnum::BETWEEN:
				assert($expr instanceof Expr\Between);
				$isNullable = array_reduce(
					array_map($this->resolveExprType(...), [$expr->expression, $expr->min, $expr->max]),
					static fn (bool $isNullable, QueryResultField $f) => $isNullable || $f->isNullable,
					false,
				);

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\IntType(),
					$isNullable,
				);
			case Expr\ExprTypeEnum::PLACEHOLDER:
				$this->positionalPlaceholderCount++;

				// TODO: is VARCHAR just a side-effect of the way mysqli binds the parameters?
				return new QueryResultField($this->getNodeContent($expr), new Schema\DbType\VarcharType(), true);
			case Expr\ExprTypeEnum::TUPLE:
				assert($expr instanceof Expr\Tuple);
				$innerFields = array_map($this->resolveExprType(...), $expr->expressions);
				$innerTypes = array_map(static fn (QueryResultField $f) => $f->type, $innerFields);

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\TupleType($innerTypes, false),
					$this->isAnyFieldNullable($innerFields),
				);
			case Expr\ExprTypeEnum::IN:
				assert($expr instanceof Expr\In);
				$leftResult = $this->resolveExprType($expr->left);
				$rightResult = $this->resolveExprType($expr->right);
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

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\IntType(),
					$leftResult->isNullable || $rightResult->isNullable,
				);
			case Expr\ExprTypeEnum::LIKE:
				assert($expr instanceof Expr\Like);
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

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\IntType(),
					$expressionResult->isNullable || $patternResult->isNullable,
				);
			case Expr\ExprTypeEnum::FUNCTION_CALL:
				assert($expr instanceof Expr\FunctionCall\FunctionCall);
				$position = 0;
				$arguments = $expr->getArguments();
				$normalizedFunctionName = strtoupper($expr->getFunctionName());

				foreach ($arguments as $arg) {
					$position++;
					$resolvedArg = $this->resolveExprType($arg);

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

				// TODO: handle this more elegantly. For now it's just like this so that I have a function to use
				// for tests.
				if ($expr instanceof Expr\FunctionCall\StandardFunctionCall && $normalizedFunctionName === 'AVG') {
					if (count($arguments) !== 1) {
						$this->errors[] = new AnalyserError(
							AnalyserErrorMessageBuilder::createMismatchedFunctionArgumentsErrorMessage(
								$expr->getFunctionName(),
								count($arguments),
								[1],
							),
						);
					}
				} else {
					$this->errors[] = new AnalyserError("Unhandled function: {$expr->getFunctionName()}");
				}

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\MixedType(),
					true,
				);
			case Expr\ExprTypeEnum::CASE_OP:
				assert($expr instanceof Expr\CaseOp);

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

				return new QueryResultField(
					$this->getNodeContent($expr),
					$type,
					$isNullable,
				);
			case Expr\ExprTypeEnum::EXISTS:
				assert($expr instanceof Expr\Exists);
				$this->getSubqueryAnalyser($expr->subquery)->analyse();

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\IntType(),
					false,
				);
			case Expr\ExprTypeEnum::ASSIGNMENT:
				assert($expr instanceof Expr\Assignment);
				$this->resolveExprType($expr->target);
				$value = $this->resolveExprType($expr->expression);

				return new QueryResultField(
					$this->getNodeContent($expr),
					$value->type,
					$value->isNullable,
				);
			default:
				$this->errors[] = new AnalyserError("Unhandled expression type: {$expr::getExprType()->value}");

				return new QueryResultField(
					$this->getNodeContent($expr),
					new Schema\DbType\MixedType(),
					true,
				);
		}
	}

	private function getNodeContent(Node $node): string
	{
		return $node->getStartPosition()->findSubstringToEndPosition($this->query, $node->getEndPosition());
	}

	private function getSubqueryAnalyser(SelectQuery $subquery): self
	{
		$other = new self(
			$this->dbReflection,
			$subquery,
			/** query is used for {@see getNodeContent()} and positions in $subquery are relative to the whole query */
			$this->query,
			new ColumnResolver($this->dbReflection, $this->columnResolver),
		);
		// phpcs:disable SlevomatCodingStandard.PHP.DisallowReference
		$other->errors = &$this->errors;
		$other->positionalPlaceholderCount = &$this->positionalPlaceholderCount;
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

	/** @param array<QueryResultField> $fields */
	private function isAnyFieldNullable(array $fields): bool
	{
		return array_reduce($fields, static fn (bool $carry, QueryResultField $f) => $carry || $f->isNullable, false);
	}

	private function getCombinedType(Schema\DbType\DbType $left, Schema\DbType\DbType $right): Schema\DbType\DbType
	{
		$typesInvolved = [
			$left::getTypeEnum()->value => $left,
			$right::getTypeEnum()->value => $right,
		];
		// TODO: handle remaining types.
		$combinedType = $typesInvolved[Schema\DbType\DbTypeEnum::VARCHAR->name]
			?? $typesInvolved[Schema\DbType\DbTypeEnum::FLOAT->name]
			?? $typesInvolved[Schema\DbType\DbTypeEnum::DECIMAL->name]
			?? $left;

		if ($combinedType::getTypeEnum() === Schema\DbType\DbTypeEnum::NULL) {
			$combinedType = $right;
		}

		return $combinedType;
	}
}
