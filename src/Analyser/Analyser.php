<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Ast\Expr;
use MariaStan\Ast\Query\QueryTypeEnum;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReferenceTypeEnum;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExprTypeEnum;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\MariaDbParser;
use MariaStan\Schema;

use function array_unique;
use function assert;
use function count;
use function reset;

class Analyser
{
	public function __construct(
		private readonly MariaDbParser $parser,
		private readonly MariaDbOnlineDbReflection $dbReflection,
	) {
	}

	/** @throws AnalyserException */
	public function analyzeQuery(string $query): AnalyserResult
	{
		try {
			$ast = $this->parser->parseSingleQuery($query);
		} catch (ParserException $e) {
			return new AnalyserResult(
				[],
				[new AnalyserError("Coudln't parse query. Got: {$e->getMessage()}")],
			);
		}

		if ($ast::getQueryType() !== QueryTypeEnum::SELECT) {
			return new AnalyserResult(
				[],
				[new AnalyserError("Unsupported query: {$ast::getQueryType()->value}")],
			);
		}

		assert($ast instanceof SelectQuery);
		[$fields, $errors] = $this->getFieldsFromSelect($ast);

		return new AnalyserResult($fields, $errors);
	}

	/**
	 * @return array{array<string, QueryResultField>, array<AnalyserError>} [[name = >field], errors]
	 * @throws AnalyserException
	 */
	private function getFieldsFromSelect(SelectQuery $selectAst): array
	{
		/** @var array<string, string> $tablesByAlias alias => table name */
		$tablesByAlias = [];
		$tableNamesInOrder = [];

		foreach ($selectAst->from ?? [] as $fromClause) {
			switch ($fromClause::getTableReferenceType()) {
				case TableReferenceTypeEnum::TABLE:
					assert($fromClause instanceof Table);
					$tablesByAlias[$fromClause->name] = $fromClause->name;

					if ($fromClause->alias !== null) {
						$tablesByAlias[$fromClause->alias] = $fromClause->name;
					}

					$tableNamesInOrder[] = $fromClause->name;

					break;
			}
		}

		$tableSchemas = [];

		foreach (array_unique($tablesByAlias) as $table) {
			$tableSchemas[$table] = $this->dbReflection->findTableSchema($table);
		}

		/** @var array<string, array<string, Schema\Column>> $columnSchemasByName */
		$columnSchemasByName = [];

		foreach ($tableSchemas as $tableSchema) {
			foreach ($tableSchema->columns as $column) {
				$columnSchemasByName[$column->name][$tableSchema->name] = $column;
			}
		}

		$fields = [];
		$errors = [];

		foreach ($selectAst->select as $selectExpr) {
			switch ($selectExpr::getSelectExprType()) {
				case SelectExprTypeEnum::REGULAR_EXPR:
					assert($selectExpr instanceof RegularExpr);
					$expr = $selectExpr->expr;

					switch ($expr::getExprType()) {
						case Expr\ExprTypeEnum::COLUMN:
							assert($expr instanceof Expr\Column);
							/** @var ?Schema\Column $columnSchema */
							$columnSchema = null;
							$candidateTables = $columnSchemasByName[$expr->name] ?? [];

							if ($expr->tableName !== null) {
								$columnSchema = $candidateTables[$expr->tableName]
									?? $candidateTables[$tablesByAlias[$expr->tableName]]
									?? null;

								if ($columnSchema === null) {
									$errors[] = new AnalyserError("Unknown column {$expr->tableName}.{$expr->name}");
								}
							} else {
								switch (count($candidateTables)) {
									case 0:
										$errors[] = new AnalyserError("Unknown column {$expr->name}");
										break;
									case 1:
										$columnSchema = reset($candidateTables);
										break;
									default:
										$errors[] = new AnalyserError("Ambiguous column {$expr->name}");
										break;
								}
							}

							$fields[$expr->name] = $columnSchema !== null
								? new QueryResultField($columnSchema->type, $columnSchema->isNullable)
								: new QueryResultField(new Schema\DbType\MixedType(), true);
							break;
					}

					break;
				case SelectExprTypeEnum::ALL_COLUMNS:
					assert($selectExpr instanceof AllColumns);
					$tableNames = $selectExpr->tableName !== null
						? [$selectExpr->tableName]
						: $tableNamesInOrder;

					foreach ($tableNames as $tableName) {
						$tableSchema = $tableSchemas[$tableName];

						foreach ($tableSchema->columns as $column) {
							$fields[$column->name] = new QueryResultField($column->type, $column->isNullable);
						}
					}

					break;
			}
		}

		return [$fields, $errors];
	}
}
