<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Ast\Expr;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Join;
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
use function array_unique;
use function assert;
use function count;
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

	public function __construct(
		private readonly MariaDbOnlineDbReflection $dbReflection,
		private readonly SelectQuery $selectAst,
		private readonly string $query,
	) {
	}

	/** @throws AnalyserException */
	public function analyse(): AnalyserResult
	{
		$this->tablesByAlias = [];
		$tableNamesInOrder = [];
		$fromClause = $this->selectAst->from;

		if ($fromClause !== null) {
			$tableNamesInOrder = $this->analyseTableReference($fromClause);
		}

		$tableSchemas = [];
		$fields = [];
		$this->errors = [];

		foreach (array_unique($this->tablesByAlias) as $table) {
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
					$fields[] = $this->resolveExprType($selectExpr->expr);
					break;
				case SelectExprTypeEnum::ALL_COLUMNS:
					assert($selectExpr instanceof AllColumns);
					$tableNames = $selectExpr->tableName !== null
						? [$selectExpr->tableName]
						: $tableNamesInOrder;

					foreach ($tableNames as $tableName) {
						$tableSchema = $tableSchemas[$tableName] ?? null;

						foreach ($tableSchema?->columns ?? [] as $column) {
							$fields[] = new QueryResultField($column->name, $column->type, $column->isNullable);
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
				$this->tablesByAlias[$fromClause->name] = $fromClause->name;

				if ($fromClause->alias !== null) {
					$this->tablesByAlias[$fromClause->alias] = $fromClause->name;
				}

				return [$fromClause->name];
			case TableReferenceTypeEnum::JOIN:
				assert($fromClause instanceof Join);
				$leftTables = $this->analyseTableReference($fromClause->leftTable);
				$rightTables = $this->analyseTableReference($fromClause->rightTable);

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

				if ($expr->tableName !== null) {
					$columnSchema = $candidateTables[$expr->tableName]
						?? $candidateTables[$this->tablesByAlias[$expr->tableName]]
						?? null;

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
							break;
						default:
							$this->errors[] = new AnalyserError("Ambiguous column {$expr->name}");
							break;
					}
				}

				return $columnSchema !== null
					? new QueryResultField($expr->name, $columnSchema->type, $columnSchema->isNullable)
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
			case Expr\ExprTypeEnum::UNARY_OP:
				assert($expr instanceof Expr\UnaryOp);
				$resolvedInnerExpr = $this->resolveExprType($expr->expr);

				$type = match ($expr->opType) {
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
					$expr->opType !== Expr\UnaryOpTypeEnum::PLUS
						? $this->getNodeContent($expr)
						: $resolvedInnerExpr->name,
					$type,
					$resolvedInnerExpr->isNullable,
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
