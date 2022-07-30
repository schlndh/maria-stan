<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\Expr\BinaryOp;
use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Expr\Column;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\FunctionCall;
use MariaStan\Ast\Expr\LiteralFloat;
use MariaStan\Ast\Expr\LiteralInt;
use MariaStan\Ast\Expr\LiteralNull;
use MariaStan\Ast\Expr\LiteralString;
use MariaStan\Ast\Expr\UnaryOp;
use MariaStan\Ast\Expr\UnaryOpTypeEnum;
use MariaStan\Ast\Query\Query;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Join;
use MariaStan\Ast\Query\TableReference\JoinTypeEnum;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\Exception\UnexpectedTokenException;
use MariaStan\Parser\Exception\UnsupportedQueryException;

use function array_slice;
use function assert;
use function chr;
use function count;
use function end;
use function is_string;
use function max;
use function min;
use function print_r;
use function str_replace;
use function str_starts_with;
use function strtr;
use function substr;

class MariaDbParserState
{
	private int $position = 0;
	private int $tokenCount;

	/** @param array<Token> $tokens */
	public function __construct(private readonly array $tokens)
	{
		$this->tokenCount = count($this->tokens);
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	public function parseStrictSingleQuery(): Query
	{
		$query = null;

		if ($this->acceptToken(TokenTypeEnum::SELECT)) {
			$query = $this->parseSelectQuery();
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
	private function parseSelectQuery(): SelectQuery
	{
		$startToken = $this->getPreviousTokenUnsafe();
		$selectExpressions = $this->parseSelectExpressionsList();
		$from = $this->parseFrom();

		if ($from !== null) {
			$endPosition = $from->getEndPosition();
		} else {
			$lastSelect = end($selectExpressions);
			assert($lastSelect instanceof SelectExpr);
			$endPosition = $lastSelect->getEndPosition();
		}

		return new SelectQuery($startToken->position, $endPosition, $selectExpressions, $from);
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
		$table = $this->expectToken(TokenTypeEnum::IDENTIFIER);
		$alias = $this->parseAlias();

		return new Table(
			$table->position,
			$this->getPreviousTokenUnsafe()->getEndPosition(),
			$this->cleanIdentifier($table->content),
			$alias,
		);
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
		$startExpressionToken = $this->findCurrentToken();

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
		$alias = $this->parseAlias();
		$prevToken = $this->getPreviousTokenUnsafe();

		return new RegularExpr($prevToken->getEndPosition(), $expr, $alias);
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseAlias(): ?string
	{
		$alias = null;

		if ($this->acceptToken(TokenTypeEnum::AS)) {
			$alias = $this->expectToken(TokenTypeEnum::IDENTIFIER);
		}

		$alias ??= $this->acceptToken(TokenTypeEnum::IDENTIFIER);

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
			$binaryOpToken = $this->findCurrentToken();

			if ($binaryOpToken === null) {
				break;
			}

			$binaryOp = $this->findBinaryOpFromToken($binaryOpToken);

			if ($binaryOp === null) {
				break;
			}

			$this->position++;
			$opPrecedence = $this->getOperatorPrecedence($binaryOp);

			if ($opPrecedence < $precedence) {
				$this->position--;
				break;
			}

			// left-associative operation => +1
			$right = $this->parseExpression($opPrecedence + 1);
			$exp = new BinaryOp($binaryOp, $exp, $right);
		}

		return $exp;
	}

	private function findBinaryOpFromToken(Token $token): ?BinaryOpTypeEnum
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
			default => null,
		};
	}

	/** @throws ParserException */
	private function getUnaryOpFromToken(Token $token): UnaryOpTypeEnum
	{
		return match ($token->type) {
			TokenTypeEnum::SINGLE_CHAR => UnaryOpTypeEnum::from($token->content),
			TokenTypeEnum::NOT => UnaryOpTypeEnum::LOGIC_NOT,
			default => throw new UnexpectedTokenException("Expected unary op token, got: {$token->content}"),
		};
	}

	// higher number = higher precedence
	private function getOperatorPrecedence(BinaryOpTypeEnum|UnaryOpTypeEnum $op): int
	{
		// https://mariadb.com/kb/en/operator-precedence/
		return match ($op) {
			// INTERVAL => 17
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
				BinaryOpTypeEnum::NOT_EQUAL => 6,
			// BETWEEN, CASE, WHEN, THEN, ELSE, END => 5
			// NOT => 4,
			BinaryOpTypeEnum::LOGIC_AND => 3,
			BinaryOpTypeEnum::LOGIC_XOR => 2,
			BinaryOpTypeEnum::LOGIC_OR => 1,
			// assignment => 0
		};
	}

	/**
	 * @phpstan-impure
	 * @throws ParserException
	 */
	private function parseUnaryExpression(): Expr
	{
		// TODO: parentheses
		$startPosition = $this->findCurrentToken()?->position
			?? throw new UnexpectedTokenException('Out of tokens');
		$ident = $this->acceptToken(TokenTypeEnum::IDENTIFIER);

		if ($ident) {
			if ($this->acceptToken('(')) {
				$arguments = $this->parseExpressionListEndedByClosingParenthesis();

				return new FunctionCall(
					$startPosition,
					$this->getPreviousTokenUnsafe()->getEndPosition(),
					$ident->content,
					$arguments,
				);
			}

			if (! $this->acceptToken('.')) {
				return new Column($startPosition, $ident->getEndPosition(), $this->cleanIdentifier($ident->content));
			}

			$tableIdent = $ident;
			$ident = $this->expectToken(TokenTypeEnum::IDENTIFIER);

			return new Column(
				$startPosition,
				$ident->getEndPosition(),
				$this->cleanIdentifier($ident->content),
				$this->cleanIdentifier($tableIdent->content),
			);
		}

		$unaryOpToken = $this->acceptAnyOfTokenTypes('+', '-', '!', '~', TokenTypeEnum::NOT);

		if ($unaryOpToken) {
			$unaryOp = $this->getUnaryOpFromToken($unaryOpToken);
			// NOT has a lower precedence than !
			$precedence = $unaryOpToken->type === TokenTypeEnum::NOT
				? 4
				: $this->getOperatorPrecedence($unaryOp);
			$expr = $this->parseExpression($precedence);

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
			($this->findCurrentToken()?->type->value ?? 'Out of tokens')
			. ' after: ' . print_r(
				array_slice($this->tokens, max($this->position - 5, 0), min($this->position, 5)),
				true,
			),
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
	private function expectToken(TokenTypeEnum|string $type): Token
	{
		$token = $this->findCurrentToken();

		if ($token === null) {
			throw new UnexpectedTokenException("Expected {$type->value}, but reached end of token list.");
		}

		if (is_string($type)) {
			if ($token->type !== TokenTypeEnum::SINGLE_CHAR || $token->content !== $type) {
				throw new UnexpectedTokenException("Expected {$type}, but found {$token->type->value}.");
			}
		} elseif ($token->type !== $type) {
			throw new UnexpectedTokenException("Expected {$type->value}, but found {$token->type->value}.");
		}

		$this->position++;

		return $token;
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
}
