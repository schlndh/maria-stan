<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\Expr;
use MariaStan\Ast\Query;
use MariaStan\Ast\SelectExpr;
use MariaStan\Parser\Exception\InvalidSqlException;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\Exception\UnsupportedQueryException;
use PHPSQLParser\PHPSQLParser;
use PHPSQLParser\utils\ExpressionType;

use function count;
use function end;
use function print_r;

class MariaDbParser
{
	private readonly PHPSQLParser $parser;

	public function __construct()
	{
		$this->parser = new PHPSQLParser();
	}

	/** @throws ParserException */
	public function parseSingleQuery(string $sqlQuery): Query
	{
		// it seems that PHPSQLParser can't handle multiple queries at once
		/** @var array<mixed>|false $result */
		$result = $this->parser->parse($sqlQuery);

		if ($result === false) {
			throw new InvalidSqlException();
		}

		if (! isset($result['SELECT'])) {
			throw new UnsupportedQueryException();
		}

		if (count($result['SELECT']) === 0) {
			throw new InvalidSqlException('Empty SELECT');
		}

		return new Query\SelectQuery(
			$this->parseSelectExpressions($result['SELECT']),
			count($result['FROM'] ?? []) > 0
				? $this->parseTableReferences($result['FROM'])
				: null,
		);
	}

	/**
	 * @param non-empty-array<array<mixed>> $selectClause
	 * @return non-empty-array<Expr|SelectExpr>
	 */
	private function parseSelectExpressions(array $selectClause): array
	{
		$result = [];

		foreach ($selectClause as $selectExpr) {
			switch ($selectExpr['expr_type']) {
				case ExpressionType::COLREF:
					if ($selectExpr['base_expr'] === '*') {
						$result[] = new SelectExpr\AllColumns(null);
						break;
					}

					if (count($selectExpr['no_quotes']['parts'] ?? []) === 2) {
						[$tableName, $colName] = $selectExpr['no_quotes']['parts'];
						$result[] = $colName === '*'
							? new SelectExpr\AllColumns($tableName)
							: new Expr\Column($colName, $tableName);
						break;
					}

					if (count($selectExpr['no_quotes']['parts'] ?? []) === 1) {
						$result[] = new Expr\Column($selectExpr['no_quotes']['parts'][0]);
						break;
					}

					throw new UnsupportedQueryException(print_r($selectExpr, true));
				default:
					throw new UnsupportedQueryException(print_r($selectExpr, true));
			}
		}

		if (count($result) === 0) {
			throw new UnsupportedQueryException(print_r($selectClause, true));
		}

		return $result;
	}

	/**
	 * @param non-empty-array<array<mixed>> $fromClause
	 * @return non-empty-array<Query\TableReference>
	 */
	private function parseTableReferences(array $fromClause): array
	{
		$result = [];

		if (count($fromClause) > 1) {
			throw new UnsupportedQueryException(print_r($fromClause, true));
		}

		$fromExpr = $fromClause[0];

		switch ($fromExpr['expr_type']) {
			case ExpressionType::TABLE:
				$tblName = end($fromExpr['no_quotes']['parts']);
				$result[] = new Query\TableReference\Table($tblName);
				break;
			default:
				throw new UnsupportedQueryException(print_r($fromExpr, true));
		}

		return $result;
	}
}
