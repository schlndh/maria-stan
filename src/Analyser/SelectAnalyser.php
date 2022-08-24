<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Ast\Expr;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\Subquery;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\Query\TableReference\TableReferenceTypeEnum;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExprTypeEnum;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Schema;

use function array_map;
use function array_merge;
use function array_reduce;
use function assert;
use function count;
use function in_array;
use function stripos;

final class SelectAnalyser
{
	/** @var array<AnalyserError> */
	private array $errors = [];
	private readonly ColumnResolver $columnResolver;

	public function __construct(
		private readonly MariaDbOnlineDbReflection $dbReflection,
		private readonly SelectQuery $selectAst,
		private readonly string $query,
		?ColumnResolver $columnResolver = null,
	) {
		$this->columnResolver = $columnResolver ?? new ColumnResolver($this->dbReflection);
	}

	private function init(): void
	{
		// Prevent phpstan from incorrectly remembering these values.
		// e.g. errors like: Offset string on array{} on left side of ?? does not exist.
		$this->errors = [];
	}

	/** @throws AnalyserException */
	public function analyse(): AnalyserResult
	{
		$this->init();
		$fromClause = $this->selectAst->from;

		if ($fromClause !== null) {
			try {
				$this->analyseTableReference($fromClause);
			} catch (AnalyserException | DbReflectionException $e) {
				$this->errors[] = new AnalyserError($e->getMessage());
			}
		}

		$fields = [];

		foreach ($this->selectAst->select as $selectExpr) {
			switch ($selectExpr::getSelectExprType()) {
				case SelectExprTypeEnum::REGULAR_EXPR:
					assert($selectExpr instanceof RegularExpr);
					$resolvedField = $this->resolveExprType($selectExpr->expr);

					if ($selectExpr->alias !== null && $selectExpr->alias !== $resolvedField->name) {
						$resolvedField = $resolvedField->getRenamed($selectExpr->alias);
					}

					$fields[] = $resolvedField;
					break;
				case SelectExprTypeEnum::ALL_COLUMNS:
					assert($selectExpr instanceof AllColumns);
					$fields = array_merge($fields, $this->columnResolver->resolveAllColumns($selectExpr->tableName));
					break;
			}
		}

		return new AnalyserResult($fields, $this->errors);
	}

	/**
	 * @return array<string> table names in order
	 * @throws AnalyserException|DbReflectionException
	 */
	private function analyseTableReference(TableReference $fromClause): array
	{
		switch ($fromClause::getTableReferenceType()) {
			case TableReferenceTypeEnum::TABLE:
				assert($fromClause instanceof Table);
				$this->columnResolver->registerTable($fromClause->name, $fromClause->alias);

				return [$fromClause->alias ?? $fromClause->name];
			case TableReferenceTypeEnum::SUBQUERY:
				assert($fromClause instanceof Subquery);
				$subqueryResult = $this->getSubqueryAnalyser($fromClause->query)->analyse();
				$this->columnResolver->registerSubquery($subqueryResult->resultFields, $fromClause->alias);

				return [$fromClause->alias];
			case TableReferenceTypeEnum::JOIN:
				assert($fromClause instanceof Join);
				$leftTables = $this->analyseTableReference($fromClause->leftTable);
				$rightTables = $this->analyseTableReference($fromClause->rightTable);

				if ($fromClause->joinType === JoinTypeEnum::LEFT_OUTER_JOIN) {
					foreach ($rightTables as $rightTable) {
						$this->columnResolver->registerOuterJoinedTable($rightTable);
					}
				} elseif ($fromClause->joinType === JoinTypeEnum::RIGHT_OUTER_JOIN) {
					foreach ($leftTables as $leftTable) {
						$this->columnResolver->registerOuterJoinedTable($leftTable);
					}
				}

				return array_merge($leftTables, $rightTables);
		}

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
				$rightResult = $this->resolveExprType($expr->right);
				$lt = $leftResult->type::getTypeEnum();
				$rt = $rightResult->type::getTypeEnum();
				$typesInvolved = [
					$lt->value => 1,
					$rt->value => 1,
				];

				if (isset($typesInvolved[Schema\DbType\DbTypeEnum::NULL->value])) {
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
				// TODO: support row subqueries: e.g. for = operator
				assert(count($result->resultFields) === 1);

				return new QueryResultField(
					$this->getNodeContent($expr),
					$result->resultFields[0]->type,
					// TODO: Change it to false if we can statically determine that the query will always return
					// a result: e.g. SELECT 1
					true,
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
		// phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference
		$other->errors = &$this->errors;

		return $other;
	}
}
