<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Ast\Expr;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\Query\TableReference\TableReferenceTypeEnum;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExprTypeEnum;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Schema;

use function array_merge;
use function array_search;
use function assert;
use function count;
use function in_array;
use function key;
use function reset;
use function stripos;

final class SelectAnalyser
{
	/** @var array<string, array<string, Schema\Column>> $columnSchemasByName */
	private array $columnSchemasByName = [];

	/** @var array<string, string> $tablesByAlias alias => table name */
	private array $tablesByAlias = [];

	/** @var array<AnalyserError> */
	private array $errors = [];

	/** @var array<string, bool> */
	private array $outerJoinedTableMap = [];

	public function __construct(
		private readonly MariaDbOnlineDbReflection $dbReflection,
		private readonly SelectQuery $selectAst,
		private readonly string $query,
	) {
	}

	private function init(): void
	{
		// Prevent phpstan from incorrectly remembering these values.
		// e.g. errors like: Offset string on array{} on left side of ?? does not exist.
		$this->tablesByAlias = [];
		$this->errors = [];
	}

	/** @throws AnalyserException */
	public function analyse(): AnalyserResult
	{
		$this->init();
		$tableNamesInOrder = [];
		$fromClause = $this->selectAst->from;

		if ($fromClause !== null) {
			$tableNamesInOrder = $this->analyseTableReference($fromClause);
		}

		$tableSchemas = [];
		$fields = [];

		foreach ($tableNamesInOrder as $table) {
			if (isset($this->tablesByAlias[$table])) {
				$table = $this->tablesByAlias[$table];
			}

			if (isset($tableSchemas[$table])) {
				continue;
			}

			try {
				$tableSchemas[$table] = $this->dbReflection->findTableSchema($table);
			} catch (DbReflectionException $e) {
				$this->errors[] = new AnalyserError($e->getMessage());
			}
		}

		$this->columnSchemasByName = [];

		foreach ($tableSchemas as $tableSchema) {
			foreach ($tableSchema->columns as $column) {
				$this->columnSchemasByName[$column->name][$tableSchema->name] = $column;
			}
		}

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
					$tableNames = $selectExpr->tableName !== null
						? [$selectExpr->tableName]
						: $tableNamesInOrder;

					foreach ($tableNames as $tableName) {
						$normalizedTableName = $this->tablesByAlias[$tableName] ?? $tableName;
						$tableSchema = $tableSchemas[$normalizedTableName] ?? null;
						$isOuterTable = $this->outerJoinedTableMap[$tableName] ?? false;

						foreach ($tableSchema?->columns ?? [] as $column) {
							$fields[] = new QueryResultField(
								$column->name,
								$column->type,
								$column->isNullable || $isOuterTable,
							);
						}
					}

					break;
			}
		}

		return new AnalyserResult($fields, $this->errors);
	}

	/** @return array<string> table names in order */
	private function analyseTableReference(TableReference $fromClause): array
	{
		switch ($fromClause::getTableReferenceType()) {
			case TableReferenceTypeEnum::TABLE:
				assert($fromClause instanceof Table);

				if ($fromClause->alias !== null) {
					$this->tablesByAlias[$fromClause->alias] = $fromClause->name;
				}

				return [$fromClause->alias ?? $fromClause->name];
			case TableReferenceTypeEnum::JOIN:
				assert($fromClause instanceof Join);
				$leftTables = $this->analyseTableReference($fromClause->leftTable);
				$rightTables = $this->analyseTableReference($fromClause->rightTable);
				assert(count($leftTables) > 0);
				// JOIN is left associative
				assert(count($rightTables) === 1);

				if ($fromClause->joinType === JoinTypeEnum::LEFT_OUTER_JOIN) {
					$rightTable = reset($rightTables);
					$this->outerJoinedTableMap[$rightTable] = true;
				} elseif ($fromClause->joinType === JoinTypeEnum::RIGHT_OUTER_JOIN) {
					foreach ($leftTables as $leftTable) {
						$this->outerJoinedTableMap[$leftTable] = true;
					}
				}

				return array_merge($leftTables, $rightTables);
		}

		return [];
	}

	private function resolveExprType(Expr\Expr $expr): QueryResultField
	{
		// TODO: handle all expression types
		switch ($expr::getExprType()) {
			case Expr\ExprTypeEnum::COLUMN:
				assert($expr instanceof Expr\Column);
				/** @var ?Schema\Column $columnSchema */
				$columnSchema = null;
				$candidateTables = $this->columnSchemasByName[$expr->name] ?? [];
				$tableName = null;

				if ($expr->tableName !== null) {
					$columnSchema = $candidateTables[$expr->tableName]
						?? $candidateTables[$this->tablesByAlias[$expr->tableName]]
						?? null;
					$tableName = $expr->tableName;

					if ($columnSchema === null) {
						$this->errors[] = new AnalyserError("Unknown column {$expr->tableName}.{$expr->name}");
					}
				} else {
					switch (count($candidateTables)) {
						case 0:
							$this->errors[] = new AnalyserError("Unknown column {$expr->name}");
							break;
						case 1:
							$columnSchema = reset($candidateTables);
							$tableName = key($candidateTables);
							$alias = array_search($tableName, $this->tablesByAlias, true);

							if ($alias !== false) {
								$tableName = $alias;
							}

							break;
						default:
							$this->errors[] = new AnalyserError("Ambiguous column {$expr->name}");
							break;
					}
				}

				$isOuterTable = $this->outerJoinedTableMap[$tableName] ?? false;

				return $columnSchema !== null
					? new QueryResultField($expr->name, $columnSchema->type, $columnSchema->isNullable || $isOuterTable)
					: new QueryResultField($expr->name, new Schema\DbType\MixedType(), true);
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
				$resolvedInnerExpr = $this->resolveExprType($expr->expr);

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
		$length = $node->getEndPosition()->offset - $node->getStartPosition()->offset;

		return $node->getStartPosition()->findSubstringStartingWithPosition($this->query, $length);
	}
}
