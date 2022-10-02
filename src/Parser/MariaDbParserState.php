<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\CommonTableExpression;
use MariaStan\Ast\DirectionEnum;
use MariaStan\Ast\Expr\Between;
use MariaStan\Ast\Expr\BinaryOp;
use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Expr\CaseOp;
use MariaStan\Ast\Expr\CastType\BinaryCastType;
use MariaStan\Ast\Expr\CastType\CastType;
use MariaStan\Ast\Expr\CastType\CharCastType;
use MariaStan\Ast\Expr\CastType\DateCastType;
use MariaStan\Ast\Expr\CastType\DateTimeCastType;
use MariaStan\Ast\Expr\CastType\DaySecondCastType;
use MariaStan\Ast\Expr\CastType\DecimalCastType;
use MariaStan\Ast\Expr\CastType\DoubleCastType;
use MariaStan\Ast\Expr\CastType\FloatCastType;
use MariaStan\Ast\Expr\CastType\IntegerCastType;
use MariaStan\Ast\Expr\CastType\TimeCastType;
use MariaStan\Ast\Expr\Column;
use MariaStan\Ast\Expr\Exists;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\FunctionCall;
use MariaStan\Ast\Expr\In;
use MariaStan\Ast\Expr\Interval;
use MariaStan\Ast\Expr\Is;
use MariaStan\Ast\Expr\Like;
use MariaStan\Ast\Expr\LiteralFloat;
use MariaStan\Ast\Expr\LiteralInt;
use MariaStan\Ast\Expr\LiteralNull;
use MariaStan\Ast\Expr\LiteralString;
use MariaStan\Ast\Expr\Placeholder;
use MariaStan\Ast\Expr\SpecialOpTypeEnum;
use MariaStan\Ast\Expr\Subquery;
use MariaStan\Ast\Expr\TimeUnitEnum;
use MariaStan\Ast\Expr\Tuple;
use MariaStan\Ast\Expr\UnaryOp;
use MariaStan\Ast\Expr\UnaryOpTypeEnum;
use MariaStan\Ast\ExprWithDirection;
use MariaStan\Ast\GroupBy;
use MariaStan\Ast\Limit;
use MariaStan\Ast\Lock\NoWait;
use MariaStan\Ast\Lock\SelectLock;
use MariaStan\Ast\Lock\SelectLockTypeEnum;
use MariaStan\Ast\Lock\SkipLocked;
use MariaStan\Ast\Lock\Wait;
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\Query\Query;
use MariaStan\Ast\Query\SelectQuery\CombinedSelectQuery;
use MariaStan\Ast\Query\SelectQuery\SelectQuery;
use MariaStan\Ast\Query\SelectQuery\SelectQueryTypeEnum;
use MariaStan\Ast\Query\SelectQuery\SimpleSelectQuery;
use MariaStan\Ast\Query\SelectQuery\WithSelectQuery;
use MariaStan\Ast\Query\SelectQueryCombinatorTypeEnum;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\Query\TableReference\TableReferenceTypeEnum;
use MariaStan\Ast\Query\TableReference\UsingJoinCondition;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Ast\WhenThen;
use MariaStan\Ast\WindowFrame;
use MariaStan\Ast\WindowFrameBound;
use MariaStan\Ast\WindowFrameTypeEnum;
use MariaStan\Parser\Exception\MissingSubqueryAliasException;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\Exception\UnexpectedTokenException;
use MariaStan\Parser\Exception\UnsupportedQueryException;

use function array_map;
use function assert;
use function chr;
use function count;
use function implode;
use function in_array;
use function is_string;
use function max;
use function reset;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strtoupper;
use function strtr;
use function substr;

class MariaDbParserState
{
	private const SELECT_PRECEDENCE_NORMAL = 0;
	private const SELECT_PRECEDENCE_UNION = 1;
	private const SELECT_PRECEDENCE_EXCEPT = self::SELECT_PRECEDENCE_UNION;
	private const SELECT_PRECEDENCE_INTERSECT = 2;

	// JOIN has higher precedence than the comma operator
	// https://dev.mysql.com/doc/refman/8.0/en/join.html
	private const JOIN_PRECEDENCE_COMMA = 0;
	private const JOIN_PRECEDENCE_EXPLICIT = 1;

	private int $position = 0;
	private int $tokenCount;

	/** @param array<Token> $tokens */
	public function __construct(
		private readonly MariaDbParser $parser,
		private readonly string $query,
		private readonly array $tokens,
	) {
		$this->tokenCount = count($this->tokens);
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	public function parseStrictSingleQuery(): Query
	{
		$query = null;

		// It seems that only SELECT can be wrapped in top-level parentheses. INSERT, UPDATE, EXPLAIN, .. don't work.
		if ($this->acceptAnyOfTokenTypes(TokenTypeEnum::SELECT, TokenTypeEnum::WITH, '(')) {
			$this->position--;
			$query = $this->parseSelectQuery();

			if ($this->tokens[0]->content === '(' && $query::getSelectQueryType() === SelectQueryTypeEnum::WITH) {
				throw new ParserException('Top-level WITH query cannot be wrapped in parentheses.');
			}
		}

		if ($query === null) {
			throw new UnsupportedQueryException();
		}

		while ($this->acceptToken(';')) {
		}

		$this->expectToken(TokenTypeEnum::END_OF_INPUT);

		return $query;
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseSelectQuery(int $precedence = self::SELECT_PRECEDENCE_NORMAL): SelectQuery
	{
		// TODO: https://mariadb.com/kb/en/common-table-expressions/
		// TODO: INTO OUTFILE/DUMPFILE/variable
		if ($this->acceptToken('(')) {
			$startPosition = $this->getPreviousToken()->position;
			$left = $this->parseSelectQuery();
			$this->expectToken(')');

			if ($precedence === self::SELECT_PRECEDENCE_NORMAL && $left instanceof SimpleSelectQuery) {
				$lock = $this->parseSelectLock();

				// UNION/EXCEPT/INTERCEPT can't follow SELECT with lock unless it's wrapped in parentheses.
				if ($lock !== null) {
					return new SimpleSelectQuery(
						$startPosition,
						$this->getPreviousToken()->getEndPosition(),
						$left->select,
						$left->from,
						$left->where,
						$left->groupBy,
						$left->having,
						$left->orderBy,
						$left->limit,
						$left->isDistinct,
						$lock,
					);
				}
			}
		} elseif ($precedence === self::SELECT_PRECEDENCE_NORMAL && $this->acceptToken(TokenTypeEnum::WITH)) {
			$startPosition = $this->getPreviousToken()->position;
			$allowRecursive = $this->acceptToken(TokenTypeEnum::RECURSIVE) !== null;
			$commonTableExpressions = [];

			do {
				$commonTableExpressions[] = $this->parseCommonTableExpression($allowRecursive);
			} while ($this->acceptToken(','));

			$selectQuery = $this->parseSelectQuery();
			$selectQuery = $this->ensureSimpleOrCombinedSelectQuery($selectQuery);

			return new WithSelectQuery(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
				$commonTableExpressions,
				$selectQuery,
				$allowRecursive,
			);
		} else {
			$startToken = $this->expectToken(TokenTypeEnum::SELECT);
			$distinctToken = $this->acceptAnyOfTokenTypes(
				TokenTypeEnum::ALL,
				TokenTypeEnum::DISTINCT,
				TokenTypeEnum::DISTINCTROW,
			);
			$isDistinct = $distinctToken !== null && $distinctToken->type !== TokenTypeEnum::ALL;
			$selectExpressions = $this->parseSelectExpressionsList();
			$from = $this->parseFrom();
			$where = $this->parseWhere();
			$groupBy = $this->parseGroupBy();
			$having = $this->parseHaving();

			$orderBy = $limit = null;

			if ($precedence === self::SELECT_PRECEDENCE_NORMAL) {
				$orderBy = $this->parseOrderBy();
				$limit = $this->parseLimit();
			}

			$lock = $this->parseSelectLock();
			$left = new SimpleSelectQuery(
				$startToken->position,
				$this->getPreviousToken()->getEndPosition(),
				$selectExpressions,
				$from,
				$where,
				$groupBy,
				$having,
				$orderBy,
				$limit,
				$isDistinct,
				$lock,
			);

			// UNION/EXCEPT/INTERCEPT can't follow SELECT with ORDER/LIMIT/lock unless it's wrapped in parentheses.
			if ($orderBy !== null || $limit !== null || $lock !== null) {
				return $left;
			}
		}

		return $this->parseRestOfCombinedQuery($left, $precedence);
	}

	/** @throws ParserException */
	private function parseRestOfCombinedQuery(
		SelectQuery $left,
		int $precedence = self::SELECT_PRECEDENCE_NORMAL,
	): SelectQuery {
		while (true) {
			$combinatorToken = $this->acceptAnyOfTokenTypes(
				TokenTypeEnum::UNION,
				TokenTypeEnum::EXCEPT,
				TokenTypeEnum::INTERSECT,
			);

			if ($combinatorToken === null) {
				break;
			}

			$combinator = match ($combinatorToken->type) {
				TokenTypeEnum::UNION => SelectQueryCombinatorTypeEnum::UNION,
				TokenTypeEnum::EXCEPT => SelectQueryCombinatorTypeEnum::EXCEPT,
				TokenTypeEnum::INTERSECT => SelectQueryCombinatorTypeEnum::INTERSECT,
				default => throw new ParserException(
					"Unmatched query combinator token {$combinatorToken->type->value}",
				),
			};
			$combinatorPrecedence = match ($combinator) {
				SelectQueryCombinatorTypeEnum::UNION => self::SELECT_PRECEDENCE_UNION,
				SelectQueryCombinatorTypeEnum::EXCEPT => self::SELECT_PRECEDENCE_EXCEPT,
				SelectQueryCombinatorTypeEnum::INTERSECT => self::SELECT_PRECEDENCE_INTERSECT,
			};

			if ($precedence > $combinatorPrecedence) {
				$this->position--;

				return $left;
			}

			$distinctAllToken = $this->acceptAnyOfTokenTypes(
				TokenTypeEnum::DISTINCT,
				TokenTypeEnum::DISTINCTROW,
				TokenTypeEnum::ALL,
			);
			$isDistinct = $distinctAllToken?->type !== TokenTypeEnum::ALL;
			// left-associative = +1
			$right = $this->parseSelectQuery($combinatorPrecedence + 1);
			$orderBy = $this->parseOrderBy();
			$limit = $this->parseLimit();
			$endPosition = $this->getPreviousToken()->getEndPosition();
			$left = $this->ensureSimpleOrCombinedSelectQuery($left);
			$right = $this->ensureSimpleOrCombinedSelectQuery($right);
			$left = new CombinedSelectQuery(
				$left->getStartPosition(),
				$endPosition,
				$combinator,
				$left,
				$right,
				$orderBy,
				$limit,
				$isDistinct,
			);

			if ($orderBy !== null || $limit !== null) {
				return $left;
			}
		}

		return $left;
	}

	/** @throws ParserException */
	private function ensureSimpleOrCombinedSelectQuery(SelectQuery $subquery): SimpleSelectQuery|CombinedSelectQuery
	{
		if (! ($subquery instanceof SimpleSelectQuery || $subquery instanceof CombinedSelectQuery)) {
			throw new ParserException(
				"Only simple/combined query is possible here. Got {$subquery::getSelectQueryType()->value} after: "
					. $this->getContextPriorToPosition($subquery->getStartPosition()),
			);
		}

		return $subquery;
	}

	/** @throws ParserException */
	private function parseCommonTableExpression(bool $allowRecursive): CommonTableExpression
	{
		$startPosition = $this->getCurrentPosition();
		$tableNameTokens = $this->parser->getTokenTypesWhichCanBeUsedAsUnquotedCteAlias();
		$name = $this->cleanIdentifier($this->expectAnyOfTokens(...$tableNameTokens)->content);
		$fieldNameTokens = $this->parser->getTokenTypesWhichCanBeUsedAsUnquotedFieldAlias();
		$columnList = $restrictCycleColumnList = null;

		if ($this->acceptToken('(')) {
			$columnList = [];

			do {
				$columnList[] = $this->cleanIdentifier($this->expectAnyOfTokens(...$fieldNameTokens)->content);
			} while ($this->acceptToken(','));

			$this->expectToken(')');
		}

		$this->expectToken(TokenTypeEnum::AS);
		$this->expectToken('(');
		$subquery = $this->parseSelectQuery();
		$subquery = $this->ensureSimpleOrCombinedSelectQuery($subquery);
		$this->expectToken(')');

		if ($allowRecursive && $this->acceptToken(TokenTypeEnum::CYCLE)) {
			$restrictCycleColumnList = [];

			do {
				$restrictCycleColumnList[] = $this->cleanIdentifier(
					$this->expectAnyOfTokens(...$fieldNameTokens)->content,
				);
			} while ($this->acceptToken(','));

			$this->expectToken(TokenTypeEnum::RESTRICT);
		}

		return new CommonTableExpression(
			$startPosition,
			$this->getPreviousToken()->getEndPosition(),
			$name,
			$subquery,
			$columnList,
			$restrictCycleColumnList,
		);
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseFrom(): ?TableReference
	{
		if (! $this->acceptToken(TokenTypeEnum::FROM)) {
			return null;
		}

		$result = $this->parseJoins();
		$this->checkSubqueryAlias($result);

		return $result;
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseJoins(int $precedence = self::JOIN_PRECEDENCE_COMMA): TableReference
	{
		$leftTable = $this->parseTableReference();

		while (true) {
			$joinType = null;
			$joinCondition = null;
			$isUnclearJoin = false;
			$joinPrecedence = self::JOIN_PRECEDENCE_EXPLICIT;
			$positionBak = $this->position;

			// TODO: NATURAL and STRAIGHT_JOIN
			if ($this->acceptToken(',')) {
				$joinType = JoinTypeEnum::CROSS_JOIN;
				$joinPrecedence = self::JOIN_PRECEDENCE_COMMA;
			} elseif ($this->acceptToken(TokenTypeEnum::CROSS)) {
				$this->expectToken(TokenTypeEnum::JOIN);
				$joinType = JoinTypeEnum::CROSS_JOIN;
			} elseif ($this->acceptToken(TokenTypeEnum::INNER)) {
				$this->expectToken(TokenTypeEnum::JOIN);
				$joinType = JoinTypeEnum::INNER_JOIN;
			} elseif ($this->acceptToken(TokenTypeEnum::LEFT)) {
				$this->acceptToken(TokenTypeEnum::OUTER);
				$this->expectToken(TokenTypeEnum::JOIN);
				$joinType = JoinTypeEnum::LEFT_OUTER_JOIN;
			} elseif ($this->acceptToken(TokenTypeEnum::RIGHT)) {
				$this->acceptToken(TokenTypeEnum::OUTER);
				$this->expectToken(TokenTypeEnum::JOIN);
				$joinType = JoinTypeEnum::RIGHT_OUTER_JOIN;
			} elseif ($this->acceptToken(TokenTypeEnum::JOIN)) {
				$isUnclearJoin = true;
			} else {
				break;
			}

			if ($joinPrecedence < $precedence) {
				$this->position = $positionBak;
				break;
			}

			$this->checkSubqueryAlias($leftTable);
			$rightTable = $joinPrecedence === self::JOIN_PRECEDENCE_COMMA
				// left-associativity => +1
				? $this->parseJoins(self::JOIN_PRECEDENCE_COMMA + 1)
				// since there are only 2 precedence levels this is equivalent to calling parseJoins(EXPLICIT + 1)
				: $this->parseTableReference();
			$this->checkSubqueryAlias($rightTable);
			$tokenPositionBak = $this->position;

			if ($isUnclearJoin) {
				$joinType = $this->acceptAnyOfTokenTypes(TokenTypeEnum::ON, TokenTypeEnum::USING)
					? JoinTypeEnum::INNER_JOIN
					: JoinTypeEnum::CROSS_JOIN;
			}

			$this->position = $tokenPositionBak;

			if ($joinType !== JoinTypeEnum::CROSS_JOIN) {
				$conditionType = $this->expectAnyOfTokens(TokenTypeEnum::ON, TokenTypeEnum::USING)->type;
				$joinCondition = $conditionType === TokenTypeEnum::ON
					? $this->parseExpression()
					: $this->parseRestOfUsingJoinCondition();
			}

			$leftTable = new Join($joinType, $leftTable, $rightTable, $joinCondition);
		}

		return $leftTable;
	}

	/** @throws ParserException */
	private function parseRestOfUsingJoinCondition(): UsingJoinCondition
	{
		$startPosition = $this->getPreviousToken()->position;
		$this->expectToken('(');
		$columnList = [];
		$fieldNameTokens = $this->parser->getTokenTypesWhichCanBeUsedAsUnquotedFieldAlias();

		do {
			$columnList[] = $this->cleanIdentifier($this->expectAnyOfTokens(...$fieldNameTokens)->content);
		} while ($this->acceptToken(','));

		$this->expectToken(')');

		return new UsingJoinCondition($startPosition, $this->getPreviousToken()->getEndPosition(), $columnList);
	}

	/** @throws ParserException */
	private function checkSubqueryAlias(TableReference $tableReference): void
	{
		if ($tableReference::getTableReferenceType() !== TableReferenceTypeEnum::SUBQUERY) {
			return;
		}

		assert($tableReference instanceof \MariaStan\Ast\Query\TableReference\Subquery);

		if ($tableReference->alias === null) {
			$subqueryStr = $tableReference->getStartPosition()
				->findSubstringToEndPosition($this->query, $tableReference->getEndPosition(), 50);

			throw new MissingSubqueryAliasException("Subquery doesn't have alias: {$subqueryStr}");
		}
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseTableReference(): TableReference
	{
		$startPosition = $this->getCurrentPosition();

		if ($this->acceptToken('(')) {
			if ($this->acceptAnyOfTokenTypes(TokenTypeEnum::SELECT, TokenTypeEnum::WITH)) {
				$this->position -= 2;
				$query = $this->parseSelectQuery();
				$alias = $this->parseTableAlias();

				return new \MariaStan\Ast\Query\TableReference\Subquery(
					$startPosition,
					$this->getPreviousToken()->getEndPosition(),
					$query,
					$alias,
				);
			}

			$result = $this->parseJoins();
			$this->expectToken(')');

			if ($result instanceof \MariaStan\Ast\Query\TableReference\Subquery && $result->alias === null) {
				$newQuery = $this->parseRestOfCombinedQuery($result->query);
				$queryChanged = $newQuery !== $result->query;

				// Can't parse both UNION and alias at the same time - alias must be after )
				$alias = ! $queryChanged
					? $this->parseTableAlias()
					: null;

				if ($alias !== null || $queryChanged) {
					return new \MariaStan\Ast\Query\TableReference\Subquery(
						$startPosition,
						$this->getPreviousToken()->getEndPosition(),
						$newQuery,
						$alias,
					);
				}
			}

			return $result;
		}

		$table = $this->expectToken(TokenTypeEnum::IDENTIFIER);
		$alias = $this->parseTableAlias();

		return new Table(
			$table->position,
			$this->getPreviousToken()->getEndPosition(),
			$this->cleanIdentifier($table->content),
			$alias,
		);
	}

	/** @throws ParserException */
	private function parseWhere(): ?Expr
	{
		if (! $this->acceptToken(TokenTypeEnum::WHERE)) {
			return null;
		}

		return $this->parseExpression();
	}

	/** @throws ParserException */
	private function parseHaving(): ?Expr
	{
		if (! $this->acceptToken(TokenTypeEnum::HAVING)) {
			return null;
		}

		return $this->parseExpression();
	}

	/**
	 * @phpstan-impure
	 * @return non-empty-array<SelectExpr>
	 * @throws ParserException
	 */
	private function parseSelectExpressionsList(): array
	{
		$result = [$this->parseSelectExpression()];

		while ($this->acceptToken(',')) {
			$result[] = $this->parseSelectExpression();
		}

		return $result;
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseSelectExpression(): SelectExpr
	{
		$startExpressionToken = $this->getCurrentToken();

		if ($this->acceptToken('*')) {
			return new AllColumns($startExpressionToken->position, $startExpressionToken->getEndPosition());
		}

		$position = $this->position;
		// TODO: in some keywords can be used as an identifier
		$ident = $this->acceptToken(TokenTypeEnum::IDENTIFIER);

		if ($ident && $this->acceptToken('.') && $this->acceptToken('*')) {
			$prevToken = $this->getPreviousToken();

			return new AllColumns(
				$startExpressionToken->position,
				$prevToken->getEndPosition(),
				$this->cleanIdentifier($ident->content),
			);
		}

		unset($ident);
		$this->position = $position;
		$expr = $this->parseExpression();
		$alias = $this->parseFieldAlias();
		$prevToken = $this->getPreviousToken();

		return new RegularExpr($prevToken->getEndPosition(), $expr, $alias);
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseFieldAlias(): ?string
	{
		$alias = null;
		$tokenTypes = $this->parser->getTokenTypesWhichCanBeUsedAsUnquotedFieldAlias();

		if ($this->acceptToken(TokenTypeEnum::AS)) {
			$alias = $this->expectAnyOfTokens(...$tokenTypes);
		}

		$alias ??= $this->acceptAnyOfTokenTypes(...$tokenTypes);

		return $alias !== null
			? $this->cleanIdentifier($alias->content)
			: null;
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseTableAlias(): ?string
	{
		$alias = null;
		$tokenTypes = $this->parser->getTokenTypesWhichCanBeUsedAsUnquotedTableAlias();

		if ($this->acceptToken(TokenTypeEnum::AS)) {
			$alias = $this->expectAnyOfTokens(...$tokenTypes);
		}

		$alias ??= $this->acceptAnyOfTokenTypes(...$tokenTypes);

		return $alias !== null
			? $this->cleanIdentifier($alias->content)
			: null;
	}

	/**
	 * @return array<Expr>
	 * @throws ParserException
	 */
	private function parseExpressionListEndedByClosingParenthesis(): array
	{
		if ($this->acceptToken(')')) {
			return [];
		}

		$result = [];

		do {
			$result[] = $this->parseExpression();
		} while ($this->acceptToken(','));

		$this->expectToken(')');

		return $result;
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseExpression(int $precedence = 0): Expr
	{
		// Precedence climbing - https://www.engr.mun.ca/~theo/Misc/exp_parsing.htm
		$exp = $this->parseUnaryExpression();

		while (true) {
			$positionBak = $this->position;
			$operatorToken = $this->findCurrentToken();
			$isNot = $operatorToken?->type === TokenTypeEnum::NOT;

			if ($isNot) {
				$this->position++;
				$operatorToken = $this->findCurrentToken();
			}

			if ($operatorToken === null) {
				$this->position = $positionBak;
				break;
			}

			$operator = $this->findBinaryOrSpecialOpFromToken($operatorToken);

			if ($operator === null) {
				$this->position = $positionBak;
				break;
			}

			$operatorsUsableWithNot = [
				SpecialOpTypeEnum::IN,
				SpecialOpTypeEnum::BETWEEN,
				SpecialOpTypeEnum::IS,
				SpecialOpTypeEnum::LIKE,
			];

			if ($isNot && ! in_array($operator, $operatorsUsableWithNot, true)) {
				throw new UnexpectedTokenException("Operator {$operator->value} cannot be used with NOT.");
			}

			$this->position++;
			$opPrecedence = $this->getOperatorPrecedence($operator);

			if ($opPrecedence < $precedence) {
				$this->position = $positionBak;
				break;
			}

			if ($operator === SpecialOpTypeEnum::IS) {
				$exp = $this->parseRestOfIsOperator($exp);
			} elseif ($operator === SpecialOpTypeEnum::IN) {
				$exp = $this->parseRestOfInOperator($exp);
			} else {
				// INTERVAL is handled as a unary operator
				assert($operator !== SpecialOpTypeEnum::INTERVAL);
				// left-associative operation => +1
				$right = $this->parseExpression($opPrecedence + ($this->isRightAssociative($operator) ? 0 : 1));
				$exp = $operator instanceof BinaryOpTypeEnum
					? new BinaryOp($operator, $exp, $right)
					: match ($operator) {
						SpecialOpTypeEnum::BETWEEN => $this->parseRestOfBetweenOperator($exp, $right),
						SpecialOpTypeEnum::LIKE => $this->parseRestOfLikeOperator($exp, $right),
					};
			}

			if ($isNot) {
				$exp = new UnaryOp(
					$exp->getStartPosition(),
					$exp->getEndPosition(),
					UnaryOpTypeEnum::LOGIC_NOT,
					$exp,
				);
			}
		}

		return $exp;
	}

	/** @throws ParserException */
	private function parseRestOfBetweenOperator(Expr $left, Expr $min): Between
	{
		static $precedence = null;
		// BETWEEN is right-associative so no + 1
		$precedence ??= $this->getOperatorPrecedence(SpecialOpTypeEnum::BETWEEN);
		$this->expectToken(TokenTypeEnum::AND);
		$max = $this->parseExpression($precedence);

		return new Between($left, $min, $max);
	}

	/** @throws ParserException */
	private function parseRestOfLikeOperator(Expr $expression, Expr $pattern): Like
	{
		static $precedence = null;
		$escapeChar = null;

		if ($this->acceptToken(TokenTypeEnum::ESCAPE)) {
			$precedence ??= $this->getOperatorPrecedence(SpecialOpTypeEnum::LIKE) + 1;
			$escapeChar = $this->parseExpression($precedence);
		}

		return new Like($expression, $pattern, $escapeChar);
	}

	/** @throws ParserException */
	private function parseRestOfIsOperator(Expr $left): Is|UnaryOp
	{
		static $precedence = null;
		// IS is left-associative so + 1
		$precedence ??= $this->getOperatorPrecedence(SpecialOpTypeEnum::IS) + 1;
		$isNot = $this->acceptToken(TokenTypeEnum::NOT) !== null;

		$testToken = $this->expectAnyOfTokens(
			TokenTypeEnum::NULL,
			TokenTypeEnum::UNKNOWN,
			TokenTypeEnum::TRUE,
			TokenTypeEnum::FALSE,
		);
		$test = match ($testToken->type) {
			TokenTypeEnum::TRUE => true,
			TokenTypeEnum::FALSE => false,
			default => null,
		};

		$currentToken = $this->findCurrentToken();

		if ($currentToken !== null) {
			$operator = $this->findBinaryOrSpecialOpFromToken($currentToken);

			if ($operator !== null) {
				if ($precedence < $this->getOperatorPrecedence($operator)) {
					throw new UnexpectedTokenException(
						"Only NULL, UNKNOWN, TRUE and FALSE can be right of IS. Got {$currentToken->content} after: "
							. $this->getContextPriorToTokenPosition(),
					);
				}
			}
		}

		$result = new Is($left->getStartPosition(), $this->getPreviousToken()->getEndPosition(), $left, $test);

		return $isNot
			? new UnaryOp(
				$result->getStartPosition(),
				$result->getEndPosition(),
				UnaryOpTypeEnum::LOGIC_NOT,
				$result,
			)
			: $result;
	}

	/** @throws ParserException */
	private function parseRestOfInOperator(Expr $left): In
	{
		static $inPrecedence = null;
		$this->expectToken('(');
		$right = $this->parseRestOfSubqueryOrTuple(true);
		assert($right instanceof Subquery || $right instanceof Tuple);

		$nextToken = $this->findCurrentToken();

		if ($nextToken !== null) {
			$operator = $this->findBinaryOrSpecialOpFromToken($nextToken);

			if ($operator !== null) {
				$opPrecedence = $this->getOperatorPrecedence($operator);
				$inPrecedence ??= $this->getOperatorPrecedence(SpecialOpTypeEnum::IN);

				if ($opPrecedence > $inPrecedence) {
					throw new UnexpectedTokenException(
						"Got {$operator->value} after: {$this->getContextPriorToTokenPosition()}",
					);
				}
			}
		}

		return new In(
			$left->getStartPosition(),
			$this->getPreviousToken()->getEndPosition(),
			$left,
			$right,
		);
	}

	/** @throws ParserException */
	private function parseTimeUnit(): TimeUnitEnum
	{
		$token = $this->expectAnyOfTokens(
			TokenTypeEnum::IDENTIFIER,
			TokenTypeEnum::SECOND_MICROSECOND,
			TokenTypeEnum::MINUTE_MICROSECOND,
			TokenTypeEnum::MINUTE_SECOND,
			TokenTypeEnum::HOUR_MICROSECOND,
			TokenTypeEnum::HOUR_SECOND,
			TokenTypeEnum::HOUR_MINUTE,
			TokenTypeEnum::DAY_MICROSECOND,
			TokenTypeEnum::DAY_SECOND,
			TokenTypeEnum::DAY_MINUTE,
			TokenTypeEnum::DAY_HOUR,
			TokenTypeEnum::YEAR_MONTH,
		);

		return TimeUnitEnum::tryFrom(strtoupper($token->content)) ?? throw new UnexpectedTokenException(
			"Expected time unit. Got {$this->printToken($token)} after: "
				. $this->getContextPriorToTokenPosition($this->position - 1),
		);
	}

	private function findBinaryOrSpecialOpFromToken(Token $token): BinaryOpTypeEnum|SpecialOpTypeEnum|null
	{
		return match ($token->type) {
			TokenTypeEnum::SINGLE_CHAR => BinaryOpTypeEnum::tryFrom($token->content),
			TokenTypeEnum::MOD => BinaryOpTypeEnum::MODULO,
			TokenTypeEnum::DIV => BinaryOpTypeEnum::INT_DIVISION,
			TokenTypeEnum::OP_LOGIC_OR, TokenTypeEnum::OR => BinaryOpTypeEnum::LOGIC_OR,
			TokenTypeEnum::XOR => BinaryOpTypeEnum::LOGIC_XOR,
			TokenTypeEnum::OP_LOGIC_AND, TokenTypeEnum::AND => BinaryOpTypeEnum::LOGIC_AND,
			TokenTypeEnum::OP_NULL_SAFE => BinaryOpTypeEnum::NULL_SAFE_EQUAL,
			TokenTypeEnum::OP_GTE => BinaryOpTypeEnum::GREATER_OR_EQUAL,
			TokenTypeEnum::OP_LTE => BinaryOpTypeEnum::LOWER_OR_EQUAL,
			TokenTypeEnum::OP_NE => BinaryOpTypeEnum::NOT_EQUAL,
			TokenTypeEnum::OP_SHIFT_LEFT => BinaryOpTypeEnum::SHIFT_LEFT,
			TokenTypeEnum::OP_SHIFT_RIGHT => BinaryOpTypeEnum::SHIFT_RIGHT,
			TokenTypeEnum::IN => SpecialOpTypeEnum::IN,
			TokenTypeEnum::REGEXP, TokenTypeEnum::RLIKE => BinaryOpTypeEnum::REGEXP,
			TokenTypeEnum::BETWEEN => SpecialOpTypeEnum::BETWEEN,
			TokenTypeEnum::IS => SpecialOpTypeEnum::IS,
			TokenTypeEnum::LIKE => SpecialOpTypeEnum::LIKE,
			default => null,
		};
	}

	/** @throws ParserException */
	private function getUnaryOpFromToken(Token $token): UnaryOpTypeEnum|SpecialOpTypeEnum
	{
		return match ($token->type) {
			TokenTypeEnum::SINGLE_CHAR => UnaryOpTypeEnum::from($token->content),
			TokenTypeEnum::NOT => UnaryOpTypeEnum::LOGIC_NOT,
			TokenTypeEnum::BINARY => UnaryOpTypeEnum::BINARY,
			TokenTypeEnum::INTERVAL => SpecialOpTypeEnum::INTERVAL,
			default => throw new UnexpectedTokenException("Expected unary op token, got: {$token->content}"),
		};
	}

	// higher number = higher precedence
	private function getOperatorPrecedence(BinaryOpTypeEnum|UnaryOpTypeEnum|SpecialOpTypeEnum $op): int
	{
		// https://mariadb.com/kb/en/operator-precedence/
		return match ($op) {
			SpecialOpTypeEnum::INTERVAL => 17,
			// COLLATE => 16
			UnaryOpTypeEnum::BINARY => 16,
			UnaryOpTypeEnum::LOGIC_NOT => 15,
			UnaryOpTypeEnum::PLUS, UnaryOpTypeEnum::MINUS, UnaryOpTypeEnum::BITWISE_NOT => 14,
			// || as string concat => 13
			BinaryOpTypeEnum::BITWISE_XOR => 12,
			BinaryOpTypeEnum::MULTIPLICATION, BinaryOpTypeEnum::DIVISION, BinaryOpTypeEnum::INT_DIVISION,
				BinaryOpTypeEnum::MODULO => 11,
			BinaryOpTypeEnum::PLUS, BinaryOpTypeEnum::MINUS => 10,
			BinaryOpTypeEnum::SHIFT_LEFT, BinaryOpTypeEnum::SHIFT_RIGHT => 9,
			BinaryOpTypeEnum::BITWISE_AND => 8,
			BinaryOpTypeEnum::BITWISE_OR => 7,
			BinaryOpTypeEnum::EQUAL, BinaryOpTypeEnum::NULL_SAFE_EQUAL, BinaryOpTypeEnum::GREATER_OR_EQUAL,
				BinaryOpTypeEnum::GREATER, BinaryOpTypeEnum::LOWER_OR_EQUAL, BinaryOpTypeEnum::LOWER,
				BinaryOpTypeEnum::NOT_EQUAL, SpecialOpTypeEnum::IN, BinaryOpTypeEnum::REGEXP,
				SpecialOpTypeEnum::IS, SpecialOpTypeEnum::LIKE => 6,
			SpecialOpTypeEnum::BETWEEN => 5,
			// NOT - handled separately => 4,
			BinaryOpTypeEnum::LOGIC_AND => 3,
			BinaryOpTypeEnum::LOGIC_XOR => 2,
			BinaryOpTypeEnum::LOGIC_OR => 1,
			// assignment => 0
		};
	}

	private function isRightAssociative(BinaryOpTypeEnum|SpecialOpTypeEnum $op): bool
	{
		return $op === SpecialOpTypeEnum::BETWEEN;
	}

	/** @throws ParserException */
	private function parseRestOfSubqueryOrTuple(bool $strictTuple): Expr
	{
		$startPosition = $this->getPreviousToken()->position;

		if ($this->acceptAnyOfTokenTypes(TokenTypeEnum::SELECT, TokenTypeEnum::WITH)) {
			$this->position--;
			$query = $this->parseSelectQuery();
			$this->expectToken(')');

			return new Subquery($startPosition, $this->getPreviousToken()->getEndPosition(), $query);
		}

		$expressions = [$this->parseExpression()];

		while ($this->acceptToken(',')) {
			$expressions[] = $this->parseExpression();
		}

		$this->expectToken(')');

		return count($expressions) === 1 && ! $strictTuple
			? reset($expressions)
			: new Tuple($startPosition, $this->getPreviousToken()->getEndPosition(), $expressions);
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseUnaryExpression(): Expr
	{
		$startPosition = $this->getCurrentPosition();

		if ($this->acceptToken('(')) {
			return $this->parseRestOfSubqueryOrTuple(false);
		}

		$identTokenTypes = $this->parser->getTokenTypesWhichCanBeUsedAsUnquotedFieldAlias();
		$ident = $this->acceptAnyOfTokenTypes(...$identTokenTypes);

		if ($ident) {
			if ($this->acceptToken('(')) {
				$uppercaseFunctionName = strtoupper($ident->content);
				$functionCall = match ($uppercaseFunctionName) {
					'COUNT' => $this->parseRestOfCountFunctionCall($startPosition),
					'DATE_ADD', 'DATE_SUB' => $this->parseRestOfDateAddSubFunctionCall($ident),
					'ADDDATE', 'SUBDATE' => $this->parseRestOfAddSubDateFunctionCall($ident),
					'CAST' => $this->parseRestOfCastFunctionCall($startPosition),
					'POSITION' => $this->parseRestOfPositionFunctionCall($ident),
					default => null,
				};

				if ($functionCall === null) {
					$isDistinct = in_array(
						$uppercaseFunctionName,
						$this->parser->getFunctionsThatSupportDistinct(),
						true,
					) && $this->acceptAnyOfTokenTypes(TokenTypeEnum::DISTINCT, TokenTypeEnum::DISTINCTROW);
					$arguments = $this->parseExpressionListEndedByClosingParenthesis();

					if ($isDistinct && count($arguments) === 0) {
						throw new UnexpectedTokenException(
							'Expected at least one argument after: '
							. $this->getContextPriorToTokenPosition($this->position - 1),
						);
					}

					$functionCall = new FunctionCall\StandardFunctionCall(
						$startPosition,
						$this->getPreviousToken()->getEndPosition(),
						$ident->content,
						$arguments,
						$isDistinct,
					);
				}

				if (! $this->acceptToken(TokenTypeEnum::OVER)) {
					return $functionCall;
				}

				if (! in_array($uppercaseFunctionName, $this->parser->getWindowFunctions(), true)) {
					throw new UnexpectedTokenException(
						"{$ident->content} is not a recognized window function. Got OVER after: "
							. $this->getContextPriorToTokenPosition($this->position - 1),
					);
				}

				return $this->parseRestOfWindowFunctionCall($functionCall);
			}

			if (! $this->acceptToken('.')) {
				return new Column($startPosition, $ident->getEndPosition(), $this->cleanIdentifier($ident->content));
			}

			$tableIdent = $ident;
			$ident = $this->expectAnyOfTokens(
				...$this->parser->getTokenTypesWhichCanBeUsedAsUnquotedIdentifierAfterDot(),
			);

			return new Column(
				$startPosition,
				$ident->getEndPosition(),
				$this->cleanIdentifier($ident->content),
				$this->cleanIdentifier($tableIdent->content),
			);
		}

		$functionIdent = $this->acceptAnyOfTokenTypes(...$this->parser->getExplicitTokenTypesForFunctions());

		if ($functionIdent) {
			$canBeWithoutParentheses = in_array(
				strtoupper($functionIdent->content),
				$this->parser->getFunctionsWhichCanBeCalledWithoutParentheses(),
				true,
			);
			$hasParentheses = $this->acceptToken('(') !== null;
			$arguments = $hasParentheses
				? $this->parseExpressionListEndedByClosingParenthesis()
				: [];

			if ($canBeWithoutParentheses || $hasParentheses) {
				return new FunctionCall\StandardFunctionCall(
					$startPosition,
					$this->getPreviousToken()->getEndPosition(),
					$functionIdent->content,
					$arguments,
				);
			}

			throw new UnexpectedTokenException(
				"Got {$this->printToken($functionIdent)} after: " . $this->getContextPriorToTokenPosition(),
			);
		}

		$positionalPlaceholderToken = $this->acceptToken('?');

		if ($positionalPlaceholderToken) {
			return new Placeholder(
				$positionalPlaceholderToken->position,
				$positionalPlaceholderToken->getEndPosition(),
			);
		}

		$unaryOpToken = $this->acceptAnyOfTokenTypes(
			'+',
			'-',
			'!',
			'~',
			TokenTypeEnum::NOT,
			TokenTypeEnum::INTERVAL,
			TokenTypeEnum::BINARY,
		);

		if ($unaryOpToken) {
			$unaryOp = $this->getUnaryOpFromToken($unaryOpToken);

			if ($unaryOp === SpecialOpTypeEnum::INTERVAL) {
				if ($this->position < 2) {
					throw new UnexpectedTokenException('INTERVAL cannot be within first 2 tokens.');
				}

				// TODO: disallow standalone INTERVAL with unary +-
				$tokenBeforeInterval = $this->tokens[$this->position - 2];

				if (
					$tokenBeforeInterval->type !== TokenTypeEnum::SINGLE_CHAR
					|| ! in_array($tokenBeforeInterval->content, ['+', '-'], true)
				) {
					throw new UnexpectedTokenException(
						'Got unexpected token INTERVAL after: '
							. $this->getContextPriorToTokenPosition($this->position - 1),
					);
				}

				$this->position--;

				return $this->parseUncheckedInterval();
			}

			// NOT has a lower precedence than !
			$precedence = $unaryOpToken->type === TokenTypeEnum::NOT
				? 4
				: $this->getOperatorPrecedence($unaryOp);
			$expr = $this->parseExpression($precedence);
			assert($unaryOp instanceof UnaryOpTypeEnum);

			return new UnaryOp($startPosition, $this->getPreviousToken()->getEndPosition(), $unaryOp, $expr);
		}

		$literalInt = $this->acceptToken(TokenTypeEnum::LITERAL_INT);

		if ($literalInt) {
			return new LiteralInt($startPosition, $literalInt->getEndPosition(), (int) $literalInt->content);
		}

		$literalFloat = $this->acceptToken(TokenTypeEnum::LITERAL_FLOAT);

		if ($literalFloat) {
			return new LiteralFloat($startPosition, $literalFloat->getEndPosition(), (float) $literalFloat->content);
		}

		$literalNull = $this->acceptToken(TokenTypeEnum::NULL);

		if ($literalNull) {
			return new LiteralNull($startPosition, $literalNull->getEndPosition());
		}

		$literalString = $this->acceptToken(TokenTypeEnum::LITERAL_STRING);

		if ($literalString) {
			$nextLiteralString = $literalString;
			$literalStringContent = '';
			$firstConcatPart = null;

			do {
				$lastLiteralString = $nextLiteralString;
				$cleanedContent = $this->cleanStringLiteral($lastLiteralString->content);
				$firstConcatPart ??= $cleanedContent;
				$literalStringContent .= $cleanedContent;
				$nextLiteralString = $this->acceptToken(TokenTypeEnum::LITERAL_STRING);
			} while ($nextLiteralString !== null);

			return new LiteralString(
				$startPosition,
				$lastLiteralString->getEndPosition(),
				$literalStringContent,
				$firstConcatPart,
			);
		}

		$case = $this->acceptToken(TokenTypeEnum::CASE);

		if ($case !== null) {
			return $this->parseRestOfCaseOperator($case);
		}

		if ($this->acceptToken(TokenTypeEnum::EXISTS)) {
			$this->expectToken('(');
			$subquery = $this->parseSelectQuery();
			$this->expectToken(')');

			return new Exists($startPosition, $this->getPreviousToken()->getEndPosition(), $subquery);
		}

		throw new UnexpectedTokenException(
			"Unexpected token: {$this->printToken($this->findCurrentToken())} after: "
				. $this->getContextPriorToTokenPosition(),
		);
	}

	/** @throws ParserException */
	private function parseRestOfCaseOperator(Token $caseToken): CaseOp
	{
		$compareValue = $this->getCurrentToken()->type === TokenTypeEnum::WHEN
			? null
			: $this->parseExpression();
		$conditions = [];

		while ($this->acceptToken(TokenTypeEnum::WHEN)) {
			$startPosition = $this->getPreviousToken()->position;
			$when = $this->parseExpression();
			$this->expectToken(TokenTypeEnum::THEN);
			$then = $this->parseExpression();
			$conditions[] = new WhenThen($startPosition, $then->getEndPosition(), $when, $then);
		}

		if (count($conditions) === 0) {
			throw new UnexpectedTokenException('Expected WHEN after: ' . $this->getContextPriorToTokenPosition());
		}

		$else = $this->acceptToken(TokenTypeEnum::ELSE)
			? $this->parseExpression()
			: null;

		$this->expectToken(TokenTypeEnum::END);

		return new CaseOp(
			$caseToken->position,
			$this->getPreviousToken()->getEndPosition(),
			$compareValue,
			$conditions,
			$else,
		);
	}

	/** @throws ParserException */
	private function parseUncheckedInterval(): Interval
	{
		$startToken = $this->expectToken(TokenTypeEnum::INTERVAL);
		// No precedence here: it should be parsed completely and then there's the time unit afterwards.
		$expr = $this->parseExpression();
		$timeUnit = $this->parseTimeUnit();

		return new Interval(
			$startToken->position,
			$this->getPreviousToken()->getEndPosition(),
			$expr,
			$timeUnit,
		);
	}

	/** @throws ParserException */
	private function parseRestOfWindowFunctionCall(
		FunctionCall\FunctionCall $functionCall,
	): FunctionCall\WindowFunctionCall {
		$this->expectToken('(');
		$partitionBy = null;

		if ($this->acceptToken(TokenTypeEnum::PARTITION)) {
			$this->expectToken(TokenTypeEnum::BY);
			$partitionBy = [$this->parseExpression()];

			while ($this->acceptToken(',')) {
				$partitionBy[] = $this->parseExpression();
			}
		}

		$orderBy = $this->parseOrderBy();
		$windowFrame = null;
		$frameTypeToken = $this->acceptAnyOfTokenTypes(TokenTypeEnum::ROWS, TokenTypeEnum::RANGE);

		if ($frameTypeToken) {
			$frameType = WindowFrameTypeEnum::from($frameTypeToken->type->value);
			$hasUpperBound = $this->acceptToken(TokenTypeEnum::BETWEEN) !== null;
			$upperBound = null;
			$lowerBound = $this->parseWindowFrameBound(TokenTypeEnum::PRECEDING);

			if ($hasUpperBound) {
				$this->expectToken(TokenTypeEnum::AND);
				$upperBound = $this->parseWindowFrameBound(TokenTypeEnum::FOLLOWING);
			}

			$windowFrame = new WindowFrame(
				$frameTypeToken->position,
				$this->getPreviousToken()->getEndPosition(),
				$frameType,
				$lowerBound,
				$upperBound,
			);
		}

		$this->expectToken(')');

		return new FunctionCall\WindowFunctionCall(
			$this->getPreviousToken()->getEndPosition(),
			$functionCall,
			$partitionBy,
			$orderBy,
			$windowFrame,
		);
	}

	/** @throws ParserException */
	private function parseWindowFrameBound(TokenTypeEnum $typeToken): WindowFrameBound
	{
		$startPosition = $this->getCurrentToken()->position;
		$boundStartToken = $this->expectAnyOfTokens(
			TokenTypeEnum::CURRENT,
			TokenTypeEnum::UNBOUNDED,
			TokenTypeEnum::LITERAL_INT,
			TokenTypeEnum::TRUE,
			TokenTypeEnum::FALSE,
		);

		if ($boundStartToken->type === TokenTypeEnum::CURRENT) {
			$this->expectToken(TokenTypeEnum::ROW);
		} else {
			$this->expectToken($typeToken);
		}

		$endPosition = $this->getPreviousToken()->getEndPosition();

		return match ($boundStartToken->type) {
			TokenTypeEnum::CURRENT => WindowFrameBound::createCurrentRow($startPosition, $endPosition),
			TokenTypeEnum::UNBOUNDED => WindowFrameBound::createUnbounded($startPosition, $endPosition),
			TokenTypeEnum::LITERAL_INT, TokenTypeEnum::TRUE, TokenTypeEnum::FALSE => WindowFrameBound::createExpression(
				$startPosition,
				$endPosition,
				new LiteralInt(
					$boundStartToken->position,
					$boundStartToken->getEndPosition(),
					// phpcs:disable PSR2.Methods.FunctionCallSignature.Indent
					match ($boundStartToken->type) {
						TokenTypeEnum::LITERAL_INT => (int) $boundStartToken->content,
						TokenTypeEnum::TRUE => 1,
						TokenTypeEnum::FALSE => 1,
					},
					// phpcs:enable PSR2.Methods.FunctionCallSignature.Indent
				),
			),
			default => throw new ParserException('This should not happen'),
		};
	}

	/** @throws ParserException */
	private function parseRestOfCountFunctionCall(Position $startPosition): FunctionCall\Count
	{
		if ($this->acceptToken('*')) {
			$this->expectToken(')');

			return FunctionCall\Count::createCountAll(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
			);
		}

		if ($this->acceptAnyOfTokenTypes(TokenTypeEnum::DISTINCT, TokenTypeEnum::DISTINCTROW)) {
			$arguments = $this->parseExpressionListEndedByClosingParenthesis();

			if (count($arguments) === 0) {
				throw new UnexpectedTokenException(
					'Expected at least one argument for COUNT(DISTINCT ... Got: '
						. $this->getContextPriorToTokenPosition(),
				);
			}

			return FunctionCall\Count::createCountDistinct(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
				$arguments,
			);
		}

		$argument = $this->parseExpression();
		$this->expectToken(')');

		return FunctionCall\Count::createCount(
			$startPosition,
			$this->getPreviousToken()->getEndPosition(),
			$argument,
		);
	}

	/** @throws ParserException */
	private function parseRestOfDateAddSubFunctionCall(Token $functionToken): FunctionCall\StandardFunctionCall
	{
		$firstArg = $this->parseExpression();
		$this->expectToken(',');
		$secondArg = $this->parseUncheckedInterval();
		$this->expectToken(')');

		return new FunctionCall\StandardFunctionCall(
			$functionToken->position,
			$this->getPreviousToken()->getEndPosition(),
			$functionToken->content,
			[$firstArg, $secondArg],
		);
	}

	/** @throws ParserException */
	private function parseRestOfAddSubDateFunctionCall(Token $functionToken): FunctionCall\StandardFunctionCall
	{
		$firstArg = $this->parseExpression();
		$this->expectToken(',');

		if ($this->acceptToken(TokenTypeEnum::INTERVAL)) {
			$this->position--;
			$secondArg = $this->parseUncheckedInterval();
		} else {
			$secondArg = $this->parseExpression();
		}

		$this->expectToken(')');

		return new FunctionCall\StandardFunctionCall(
			$functionToken->position,
			$this->getPreviousToken()->getEndPosition(),
			$functionToken->content,
			[$firstArg, $secondArg],
		);
	}

	/** @throws ParserException */
	private function parseRestOfCastFunctionCall(Position $startPosition): FunctionCall\Cast
	{
		$expr = $this->parseExpression();
		$this->expectToken(TokenTypeEnum::AS);
		$castType = $this->parseCastType();
		$this->expectToken(')');

		return new FunctionCall\Cast(
			$startPosition,
			$this->getPreviousToken()->getEndPosition(),
			$expr,
			$castType,
		);
	}

	/** @throws ParserException */
	private function parseCastType(): CastType
	{
		$startPosition = $this->getCurrentPosition();
		$parseIntParameter = function (): int {
			$paramToken = $this->expectAnyOfTokens(TokenTypeEnum::LITERAL_INT, TokenTypeEnum::LITERAL_FLOAT);

			if (stripos($paramToken->content, 'e') !== false) {
				throw new UnexpectedTokenException(
					"Expected literal INT/DECIMAL got '{$paramToken->content}', after: "
						. $this->getContextPriorToTokenPosition($this->position - 1),
				);
			}

			return (int) $paramToken->content;
		};
		$parseSingleOptionalIntParam = function () use ($parseIntParameter): ?int {
			$param = null;

			if ($this->acceptToken('(')) {
				$param = $parseIntParameter();
				$this->expectToken(')');
			}

			return $param;
		};

		if ($this->acceptToken(TokenTypeEnum::BINARY)) {
			$length = $parseSingleOptionalIntParam();

			return new BinaryCastType($startPosition, $this->getPreviousToken()->getEndPosition(), $length);
		}

		if ($this->acceptToken(TokenTypeEnum::CHAR)) {
			$characterSet = $collation = null;
			$length = $parseSingleOptionalIntParam();

			if ($this->acceptToken(TokenTypeEnum::CHARACTER)) {
				$this->expectToken(TokenTypeEnum::SET);
				$characterSet = $this->cleanIdentifier($this->expectToken(TokenTypeEnum::IDENTIFIER)->content);
			}

			if ($this->acceptToken(TokenTypeEnum::COLLATE)) {
				$collation = $this->cleanIdentifier($this->expectToken(TokenTypeEnum::IDENTIFIER)->content);
			}

			return new CharCastType(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
				$length,
				$characterSet,
				$collation,
			);
		}

		if ($this->acceptToken(TokenTypeEnum::DATE)) {
			return new DateCastType($startPosition, $this->getPreviousToken()->getEndPosition());
		}

		if ($this->acceptToken(TokenTypeEnum::DATETIME)) {
			$microsecondPrecision = $parseSingleOptionalIntParam();

			return new DateTimeCastType(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
				$microsecondPrecision,
			);
		}

		if ($this->acceptToken(TokenTypeEnum::DECIMAL)) {
			$maxDigits = $maxDecimals = null;

			if ($this->acceptToken('(')) {
				$maxDigits = $parseIntParameter();

				if ($this->acceptToken(',')) {
					$maxDecimals = $parseIntParameter();
				}

				$this->expectToken(')');
			}

			return new DecimalCastType(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
				$maxDigits ?? 10,
				$maxDecimals ?? 0,
			);
		}

		if ($this->acceptToken(TokenTypeEnum::DOUBLE)) {
			return new DoubleCastType($startPosition, $this->getPreviousToken()->getEndPosition());
		}

		if ($this->acceptToken(TokenTypeEnum::FLOAT)) {
			return new FloatCastType($startPosition, $this->getPreviousToken()->getEndPosition());
		}

		$intToken = $this->acceptAnyOfTokenTypes(
			TokenTypeEnum::INTEGER,
			TokenTypeEnum::SIGNED,
			TokenTypeEnum::UNSIGNED,
		);

		if ($intToken) {
			if ($intToken->type !== TokenTypeEnum::INTEGER) {
				$this->acceptToken(TokenTypeEnum::INTEGER);
			}

			$isSigned = $intToken->type !== TokenTypeEnum::UNSIGNED;

			return new IntegerCastType($startPosition, $this->getPreviousToken()->getEndPosition(), $isSigned);
		}

		if ($this->acceptToken(TokenTypeEnum::TIME)) {
			$microsecondPrecision = $parseSingleOptionalIntParam();

			return new TimeCastType(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
				$microsecondPrecision,
			);
		}

		if ($this->acceptToken(TokenTypeEnum::INTERVAL)) {
			$this->expectToken(TokenTypeEnum::DAY_SECOND);
			$this->expectToken('(');
			$microsecondPrecision = $parseIntParameter();
			$this->expectToken(')');

			return new DaySecondCastType(
				$startPosition,
				$this->getPreviousToken()->getEndPosition(),
				$microsecondPrecision,
			);
		}

		throw new UnexpectedTokenException(
			"Expected cast type, got {$this->printToken($this->findCurrentToken())}, after: "
				. $this->getContextPriorToTokenPosition(),
		);
	}

	/** @throws ParserException */
	private function parseRestOfPositionFunctionCall(Token $functionIdent): FunctionCall\Position
	{
		$parenthesisStart = $this->getPreviousToken()->position->offset;
		$functionIdentEnd = $functionIdent->getEndPosition()->offset;

		// https://dev.mysql.com/doc/refman/8.0/en/function-resolution.html
		if ($functionIdentEnd < $parenthesisStart) {
			throw new ParserException(
				"POSITION cannot have a space between function name and parenthesis, at: "
					. $this->getContextPriorToTokenPosition(),
			);
		}

		static $inPrecedence = null;
		$inPrecedence ??= $this->getOperatorPrecedence(SpecialOpTypeEnum::IN);

		// If we encounter IN we want to interpret it as the separator between the two arguments
		$substrExpr = $this->parseExpression($inPrecedence + 1);
		$this->expectToken(TokenTypeEnum::IN);
		// str can contain IN operator
		$strExpr = $this->parseExpression();
		$this->expectToken(')');

		return new FunctionCall\Position(
			$functionIdent->position,
			$this->getPreviousToken()->getEndPosition(),
			$substrExpr,
			$strExpr,
		);
	}

	/** @throws ParserException */
	private function parseGroupBy(): ?GroupBy
	{
		if (! $this->acceptToken(TokenTypeEnum::GROUP)) {
			return null;
		}

		$startPosition = $this->getPreviousToken()->position;
		$this->expectToken(TokenTypeEnum::BY);
		$expressions = $this->parseListOfExprWithDirection();
		$isWithRollup = false;

		if ($this->acceptToken(TokenTypeEnum::WITH)) {
			$this->expectToken(TokenTypeEnum::ROLLUP);
			$isWithRollup = true;
		}

		return new GroupBy(
			$startPosition,
			$this->getPreviousToken()->getEndPosition(),
			$expressions,
			$isWithRollup,
		);
	}

	/** @throws ParserException */
	private function parseOrderBy(): ?OrderBy
	{
		if (! $this->acceptToken(TokenTypeEnum::ORDER)) {
			return null;
		}

		$startPosition = $this->getPreviousToken()->position;
		$this->expectToken(TokenTypeEnum::BY);
		$expressions = $this->parseListOfExprWithDirection();

		return new OrderBy(
			$startPosition,
			$this->getPreviousToken()->getEndPosition(),
			$expressions,
		);
	}

	/**
	 * @return non-empty-array<ExprWithDirection>
	 * @throws ParserException
	 */
	private function parseListOfExprWithDirection(): array
	{
		$expressions = [];

		do {
			$expr = $this->parseExpression();
			$direction = $this->parseDirectionOrDefaultAsc();
			$expressions[] = new ExprWithDirection(
				$expr->getStartPosition(),
				$this->getPreviousToken()->getEndPosition(),
				$expr,
				$direction,
			);
		} while ($this->acceptToken(','));

		return $expressions;
	}

	private function parseDirectionOrDefaultAsc(): DirectionEnum
	{
		if ($this->acceptToken(TokenTypeEnum::DESC)) {
			return DirectionEnum::DESC;
		}

		$this->acceptToken(TokenTypeEnum::ASC);

		return DirectionEnum::ASC;
	}

	/** @throws ParserException */
	private function parseLimit(): ?Limit
	{
		if (! $this->acceptToken(TokenTypeEnum::LIMIT)) {
			return null;
		}

		// TODO: implement SELECT ... OFFSET ... FETCH https://mariadb.com/kb/en/select-offset-fetch/
		$startPosition = $this->getPreviousToken()->position;
		$count = $this->parseExpression();
		$offset = null;

		if ($this->acceptToken(',')) {
			$offset = $count;
			$count = $this->parseExpression();
		} elseif ($this->acceptToken(TokenTypeEnum::OFFSET)) {
			$offset = $this->parseExpression();
		}

		return new Limit(
			$startPosition,
			$this->getPreviousToken()->position,
			$count,
			$offset,
		);
	}

	/** @throws ParserException */
	private function parseSelectLock(): ?SelectLock
	{
		if ($this->acceptToken(TokenTypeEnum::FOR)) {
			$startPosition = $this->getPreviousToken()->position;
			$this->expectToken(TokenTypeEnum::UPDATE);
			$type = SelectLockTypeEnum::UPDATE;
		} elseif ($this->acceptToken(TokenTypeEnum::LOCK)) {
			$startPosition = $this->getPreviousToken()->position;
			$this->expectToken(TokenTypeEnum::IN);
			$this->expectToken(TokenTypeEnum::SHARE);
			$this->expectToken(TokenTypeEnum::MODE);
			$type = SelectLockTypeEnum::SHARE;
		} else {
			return null;
		}

		$lockOption = null;
		$waitToken = $this->acceptAnyOfTokenTypes(TokenTypeEnum::WAIT, TokenTypeEnum::NOWAIT, TokenTypeEnum::SKIP);

		switch ($waitToken?->type) {
			case TokenTypeEnum::SKIP:
				// help phpstan
				assert($waitToken !== null);
				$this->expectToken(TokenTypeEnum::LOCKED);
				$lockOption = new SkipLocked($waitToken->position, $this->getPreviousToken()->getEndPosition());
				break;
			case TokenTypeEnum::WAIT:
				assert($waitToken !== null);
				$secondsToken = $this->expectAnyOfTokens(TokenTypeEnum::LITERAL_INT, TokenTypeEnum::LITERAL_FLOAT);
				$lockOption = new Wait(
					$waitToken->position,
					$this->getPreviousToken()->getEndPosition(),
					(float) $secondsToken->content,
				);
				break;
			case TokenTypeEnum::NOWAIT:
				assert($waitToken !== null);
				$lockOption = new NoWait($waitToken->position, $waitToken->getEndPosition());
				break;
		}

		return new SelectLock(
			$startPosition,
			$this->getPreviousToken()->getEndPosition(),
			$type,
			$lockOption,
		);
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function getPreviousToken(): Token
	{
		// It can only be called after a token was consumed.
		return $this->tokens[$this->position - 1] ?? throw new UnexpectedTokenException('No previous token');
	}

	/** @phpstan-impure */
	private function findCurrentToken(): ?Token
	{
		if ($this->position >= $this->tokenCount) {
			return null;
		}

		return $this->tokens[$this->position];
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function getCurrentToken(): Token
	{
		return $this->tokens[$this->position] ?? throw new UnexpectedTokenException('Out of tokens');
	}

	/** @phpstan-impure */
	private function acceptAnyOfTokenTypes(TokenTypeEnum|string ...$types): ?Token
	{
		if (count($types) === 0) {
			return null;
		}

		$token = $this->findCurrentToken();

		if ($token === null) {
			return null;
		}

		if ($token->type === TokenTypeEnum::SINGLE_CHAR) {
			foreach ($types as $type) {
				if ($token->content !== $type) {
					continue;
				}

				$this->position++;

				return $token;
			}

			return null;
		}

		/**
		 * Because of the if above, phpstan has a giant union for $token->type, which slows down the analysis quite
		 * significantly (2s with this comment, 50s without). Since the information is useless, let's just force
		 * phpstan to forget it.
		 *
		 * @phpstan-var TokenTypeEnum $tokenType
		 */
		$tokenType = $token->type;

		foreach ($types as $type) {
			if ($tokenType !== $type) {
				continue;
			}

			$this->position++;

			return $token;
		}

		return null;
	}

	/** @phpstan-impure */
	private function acceptToken(TokenTypeEnum|string $type): ?Token
	{
		$token = $this->findCurrentToken();

		if (is_string($type)) {
			if ($token?->type !== TokenTypeEnum::SINGLE_CHAR || $token->content !== $type) {
				return null;
			}
		} elseif ($token?->type !== $type) {
			return null;
		}

		$this->position++;

		return $token;
	}

	/**
	 * @phpstan-impure
	 * @throws UnexpectedTokenException
	 */
	private function expectAnyOfTokens(TokenTypeEnum|string ...$types): Token
	{
		if (count($types) === 0) {
			throw new UnexpectedTokenException(__METHOD__ . ' cannot be used with no token types.');
		}

		$token = $this->acceptAnyOfTokenTypes(...$types);

		if ($token !== null) {
			return $token;
		}

		$token = $this->findCurrentToken();
		$typesMsg = implode(
			', ',
			array_map(
				static fn (TokenTypeEnum|string $t) => is_string($t)
					? "'{$t}'"
					: $t->value,
				$types,
			),
		);

		if ($token === null) {
			throw new UnexpectedTokenException(
				"Expected one of {$typesMsg}, but reached end of token list after: "
					. $this->getContextPriorToTokenPosition(),
			);
		}

		throw new UnexpectedTokenException(
			"Expected one of {$typesMsg}, got {$this->printToken($token)} after: "
				. $this->getContextPriorToTokenPosition(),
		);
	}

	/**
	 * @phpstan-impure
	 * @throws UnexpectedTokenException
	 */
	private function expectToken(TokenTypeEnum|string $type): Token
	{
		$token = $this->findCurrentToken();

		if ($token === null) {
			$expectedToken = is_string($type)
				? "'{$type}'"
				: $type->value;

			throw new UnexpectedTokenException(
				"Expected {$expectedToken}, but reached end of token list after: "
				. $this->getContextPriorToTokenPosition(),
			);
		}

		if (is_string($type)) {
			if ($token->type !== TokenTypeEnum::SINGLE_CHAR || $token->content !== $type) {
				throw new UnexpectedTokenException(
					"Expected '{$type}', got {$this->printToken($token)} after: "
					. $this->getContextPriorToTokenPosition(),
				);
			}
		} elseif ($token->type !== $type) {
			throw new UnexpectedTokenException(
				"Expected {$type->value}, got {$this->printToken($token)} after: "
				. $this->getContextPriorToTokenPosition(),
			);
		}

		$this->position++;

		return $token;
	}

	/** @throws ParserException */
	private function getCurrentPosition(): Position
	{
		return $this->findCurrentToken()?->position ?? throw new UnexpectedTokenException('Out of tokens');
	}

	private function cleanIdentifier(string $identifier): string
	{
		if (! str_starts_with($identifier, '`')) {
			return $identifier;
		}

		$identifier = substr($identifier, 1, -1);

		return str_replace('``', '`', $identifier);
	}

	private function cleanStringLiteral(string $contents): string
	{
		$quotes = $contents[0];
		$contents = substr($contents, 1, -1);
		$contents = str_replace($quotes . $quotes, $quotes, $contents);
		static $map = null;

		if ($map === null) {
			$map = [
				'\\0' => "\0",
				"\\'" => "'",
				'\\"' => '"',
				'\\b' => chr(8),
				'\\n' => "\n",
				'\\r' => "\r",
				'\\t' => "\t",
				'\\Z' => chr(26),
				'\\\\' => '\\',
				'\\%' => '%',
				'\\_' => '_',
			];

			for ($i = 0; $i < 256; $i++) {
				$c = chr($i);
				$map["\\{$c}"] ??= $c;
			}
		}

		return strtr($contents, $map);
	}

	/** @param int|null $tokenPosition null = current token */
	private function getContextPriorToTokenPosition(?int $tokenPosition = null): string
	{
		$tokenPosition ??= $this->position;
		$token = $tokenPosition >= $this->tokenCount
			? null
			: $this->tokens[$tokenPosition];

		return $token !== null
			? $this->tokens[max($this->position - 5, 0)]->position
				->findSubstringToEndPosition($this->query, $token->position)
			: substr($this->query, -50);
	}

	private function getContextPriorToPosition(Position $startPosition): string
	{
		return $startPosition->findSubstringEndingWithPosition($this->query, 50);
	}

	private function printToken(?Token $token): string
	{
		if ($token === null) {
			return 'Out of tokens';
		}

		if ($token->type === TokenTypeEnum::SINGLE_CHAR) {
			return "'{$token->content}'";
		}

		return $token->type->value;
	}
}
