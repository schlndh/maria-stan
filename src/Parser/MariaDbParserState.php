<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\DirectionEnum;
use MariaStan\Ast\Expr\Between;
use MariaStan\Ast\Expr\BinaryOp;
use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Expr\Column;
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
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\Query\Query;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Ast\WindowFrame;
use MariaStan\Ast\WindowFrameBound;
use MariaStan\Ast\WindowFrameTypeEnum;
use MariaStan\Parser\Exception\InvalidSqlException;
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
use function strtoupper;
use function strtr;
use function substr;

class MariaDbParserState
{
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
		$parenthesisCount = 0;
		$query = null;

		while ($this->acceptToken('(')) {
			$parenthesisCount++;
		}

		if ($this->acceptToken(TokenTypeEnum::SELECT)) {
			$query = $this->parseSelectQuery();
		}

		if ($query === null) {
			throw new UnsupportedQueryException();
		}

		while ($parenthesisCount > 0) {
			$this->expectToken(')');
			$parenthesisCount--;
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
	private function parseSelectQuery(): SelectQuery
	{
		// TODO: https://mariadb.com/kb/en/common-table-expressions/
		// TODO: FOR UPDATE / LOCK IN SHARE MODE
		// TODO: INTO OUTFILE/DUMPFILE/variable
		$startToken = $this->getPreviousTokenUnsafe();
		$selectExpressions = $this->parseSelectExpressionsList();
		$from = $this->parseFrom();
		$where = $this->parseWhere();
		$groupBy = $this->parseGroupBy();
		$having = $this->parseHaving();
		$orderBy = $this->parseOrderBy();
		$limit = $this->parseLimit();
		$endPosition = $this->getPreviousTokenUnsafe()->getEndPosition();

		return new SelectQuery(
			$startToken->position,
			$endPosition,
			$selectExpressions,
			$from,
			$where,
			$groupBy,
			$having,
			$orderBy,
			$limit,
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

		return $this->parseJoins();
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseJoins(): TableReference
	{
		$leftTable = $this->parseTableReference();

		while (true) {
			$joinType = null;
			$onCondition = null;
			$isUnclearJoin = false;

			// TODO: NATURAL and STRAIGHT_JOIN
			if ($this->acceptToken(',')) {
				$joinType = JoinTypeEnum::CROSS_JOIN;
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

			$rightTable = $this->parseTableReference();
			$tokenPositionBak = $this->position;

			// TODO: USING(...)
			if ($isUnclearJoin) {
				$joinType = $this->acceptToken(TokenTypeEnum::ON)
					? JoinTypeEnum::INNER_JOIN
					: JoinTypeEnum::CROSS_JOIN;
			}

			$this->position = $tokenPositionBak;

			if ($joinType !== JoinTypeEnum::CROSS_JOIN) {
				$this->expectToken(TokenTypeEnum::ON);
				$onCondition = $this->parseExpression();
			}

			$leftTable = new Join($joinType, $leftTable, $rightTable, $onCondition);
		}

		return $leftTable;
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseTableReference(): TableReference
	{
		$startPosition = $this->getCurrentPosition();

		// TODO: There are many weird edge-cases when it comes to nested parentheses. A subquery can be nested
		// in multiple parentheses and the alias can be on any level. But only once. For example these work:
		// SELECT * FROM (((SELECT 1)) t); SELECT * FROM (((SELECT 1))) t;
		if ($this->acceptToken('(')) {
			if ($this->acceptToken(TokenTypeEnum::SELECT)) {
				$query = $this->parseSelectQuery();
				$this->expectToken(')');
				$alias = $this->parseTableAlias();

				if ($alias === null) {
					throw new InvalidSqlException('Subquery has to have an alias!');
				}

				return new \MariaStan\Ast\Query\TableReference\Subquery(
					$startPosition,
					$this->getPreviousTokenUnsafe()->getEndPosition(),
					$query,
					$alias,
				);
			}

			$result = $this->parseJoins();
			$this->expectToken(')');

			return $result;
		}

		$table = $this->expectToken(TokenTypeEnum::IDENTIFIER);
		$alias = $this->parseTableAlias();

		return new Table(
			$table->position,
			$this->getPreviousTokenUnsafe()->getEndPosition(),
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
			$prevToken = $this->getPreviousTokenUnsafe();

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
		$prevToken = $this->getPreviousTokenUnsafe();

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
				$exp = new UnaryOp($exp->getStartPosition(), UnaryOpTypeEnum::LOGIC_NOT, $exp);
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

		$result = new Is($left->getStartPosition(), $this->getCurrentPosition(), $left, $test);

		return $isNot
			? new UnaryOp($result->getStartPosition(), UnaryOpTypeEnum::LOGIC_NOT, $result)
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
			$this->getPreviousTokenUnsafe()->getEndPosition(),
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
			// BINARY, COLLATE => 16
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
			// CASE, WHEN, THEN, ELSE, END => 5
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
		$startPosition = $this->getPreviousTokenUnsafe()->position;

		if ($this->acceptToken(TokenTypeEnum::SELECT)) {
			$query = $this->parseSelectQuery();
			$this->expectToken(')');

			return new Subquery($startPosition, $this->getPreviousTokenUnsafe()->getEndPosition(), $query);
		}

		$expressions = [$this->parseExpression()];

		while ($this->acceptToken(',')) {
			$expressions[] = $this->parseExpression();
		}

		$this->expectToken(')');

		return count($expressions) === 1 && ! $strictTuple
			? reset($expressions)
			: new Tuple($startPosition, $this->getPreviousTokenUnsafe()->getEndPosition(), $expressions);
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
					default => null,
				};

				if ($functionCall === null) {
					$isDistinct = in_array(
						$uppercaseFunctionName,
						$this->parser->getFunctionsThatSupportDistinct(),
						true,
					) && $this->acceptToken(TokenTypeEnum::DISTINCT);
					$arguments = $this->parseExpressionListEndedByClosingParenthesis();

					if ($isDistinct && count($arguments) === 0) {
						throw new UnexpectedTokenException(
							'Expected at least one argument after: '
							. $this->getContextPriorToTokenPosition($this->position - 1),
						);
					}

					$functionCall = new FunctionCall\StandardFunctionCall(
						$startPosition,
						$this->getPreviousTokenUnsafe()->getEndPosition(),
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
					$this->getPreviousTokenUnsafe()->getEndPosition(),
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

		$unaryOpToken = $this->acceptAnyOfTokenTypes('+', '-', '!', '~', TokenTypeEnum::NOT, TokenTypeEnum::INTERVAL);

		if ($unaryOpToken) {
			$unaryOp = $this->getUnaryOpFromToken($unaryOpToken);

			if ($unaryOp === SpecialOpTypeEnum::INTERVAL) {
				if ($this->position < 2) {
					throw new UnexpectedTokenException('INTERVAL cannot be within first 2 tokens.');
				}

				// TODO: allow INTERVAL in supported functions (e.g. ADDDATE etc).
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

				// No precedence here: it should be parsed completely and then there's the time unit afterwards.
				$expr = $this->parseExpression();
				$timeUnit = $this->parseTimeUnit();

				return new Interval(
					$startPosition,
					$this->getPreviousTokenUnsafe()->getEndPosition(),
					$expr,
					$timeUnit,
				);
			}

			// NOT has a lower precedence than !
			$precedence = $unaryOpToken->type === TokenTypeEnum::NOT
				? 4
				: $this->getOperatorPrecedence($unaryOp);
			$expr = $this->parseExpression($precedence);
			assert($unaryOp instanceof UnaryOpTypeEnum);

			return new UnaryOp($startPosition, $unaryOp, $expr);
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

		throw new UnexpectedTokenException(
			"Unexpected token: {$this->printToken($this->findCurrentToken())} after: "
				. $this->getContextPriorToTokenPosition(),
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
				$this->getPreviousTokenUnsafe()->getEndPosition(),
				$frameType,
				$lowerBound,
				$upperBound,
			);
		}

		$this->expectToken(')');

		return new FunctionCall\WindowFunctionCall(
			$this->getPreviousTokenUnsafe()->getEndPosition(),
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

		$endPosition = $this->getPreviousTokenUnsafe()->getEndPosition();

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
				$this->getPreviousTokenUnsafe()->getEndPosition(),
			);
		}

		if ($this->acceptToken(TokenTypeEnum::DISTINCT)) {
			$arguments = $this->parseExpressionListEndedByClosingParenthesis();

			if (count($arguments) === 0) {
				throw new UnexpectedTokenException(
					'Expected at least one argument for COUNT(DISTINCT ... Got: '
						. $this->getContextPriorToTokenPosition(),
				);
			}

			return FunctionCall\Count::createCountDistinct(
				$startPosition,
				$this->getPreviousTokenUnsafe()->getEndPosition(),
				$arguments,
			);
		}

		$argument = $this->parseExpression();
		$this->expectToken(')');

		return FunctionCall\Count::createCount(
			$startPosition,
			$this->getPreviousTokenUnsafe()->getEndPosition(),
			$argument,
		);
	}

	/** @throws ParserException */
	private function parseGroupBy(): ?GroupBy
	{
		if (! $this->acceptToken(TokenTypeEnum::GROUP)) {
			return null;
		}

		$startPosition = $this->getPreviousTokenUnsafe()->position;
		$this->expectToken(TokenTypeEnum::BY);
		$expressions = $this->parseListOfExprWithDirection();
		$isWithRollup = false;

		if ($this->acceptToken(TokenTypeEnum::WITH)) {
			$this->expectToken(TokenTypeEnum::ROLLUP);
			$isWithRollup = true;
		}

		return new GroupBy(
			$startPosition,
			$this->getPreviousTokenUnsafe()->getEndPosition(),
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

		$startPosition = $this->getPreviousTokenUnsafe()->position;
		$this->expectToken(TokenTypeEnum::BY);
		$expressions = $this->parseListOfExprWithDirection();

		return new OrderBy(
			$startPosition,
			$this->getPreviousTokenUnsafe()->getEndPosition(),
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
				$this->getPreviousTokenUnsafe()->getEndPosition(),
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
		$startPosition = $this->getPreviousTokenUnsafe()->position;
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
			$this->getPreviousTokenUnsafe()->position,
			$count,
			$offset,
		);
	}

	/** @phpstan-impure */
	private function getPreviousTokenUnsafe(): Token
	{
		// It can only be called after a token was consumed.
		return $this->tokens[$this->position - 1];
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
