<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\Expr\Column;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\LiteralFloat;
use MariaStan\Ast\Expr\LiteralInt;
use MariaStan\Ast\Expr\UnaryOp;
use MariaStan\Ast\Expr\UnaryOpTypeEnum;
use MariaStan\Ast\Query\Query;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\SelectExpr\AllColumns;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Parser\Exception\UnexpectedTokenException;
use MariaStan\Parser\Exception\UnsupportedQueryException;

use function array_slice;
use function assert;
use function count;
use function end;
use function max;
use function min;
use function print_r;
use function str_replace;
use function str_starts_with;
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

	/** @phpstan-impure */
	public function parseStrictSingleQuery(): Query
	{
		$query = null;

		if ($this->acceptToken(TokenTypeEnum::SELECT)) {
			$query = $this->parseSelectQuery();
		}

		if ($query === null) {
			throw new UnsupportedQueryException();
		}

		while ($this->acceptSingleCharToken(';')) {
		}

		$this->expectToken(TokenTypeEnum::END_OF_INPUT);

		return $query;
	}

	/** @phpstan-impure */
	private function parseSelectQuery(): SelectQuery
	{
		$startToken = $this->getPreviousTokenUnsafe();
		$selectExpressions = $this->parseSelectExpressionsList();
		$from = $this->parseFrom();

		if ($from !== null) {
			$lastFrom = end($from);
			assert($lastFrom instanceof TableReference);
			$endPosition = $lastFrom->getEndPosition();
		} else {
			$lastSelect = end($selectExpressions);
			assert($lastSelect instanceof SelectExpr);
			$endPosition = $lastSelect->getEndPosition();
		}

		return new SelectQuery($startToken->position, $endPosition, $selectExpressions, $from);
	}

	/** @return ?non-empty-array<TableReference> */
	private function parseFrom(): ?array
	{
		if (! $this->acceptToken(TokenTypeEnum::FROM)) {
			return null;
		}

		return [$this->parseTableReference()];
	}

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
	 * @return non-empty-array<SelectExpr>
	 * @phpstan-impure
	 */
	private function parseSelectExpressionsList(): array
	{
		$result = [$this->parseSelectExpression()];

		while ($this->acceptSingleCharToken(',')) {
			$result[] = $this->parseSelectExpression();
		}

		return $result;
	}

	/** @phpstan-impure */
	private function parseSelectExpression(): SelectExpr
	{
		$startExpressionToken = $this->findCurrentToken();

		if ($this->acceptSingleCharToken('*')) {
			return new AllColumns($startExpressionToken->position, $startExpressionToken->getEndPosition());
		}

		$position = $this->position;
		// TODO: in some keywords can be used as an identifier
		$ident = $this->acceptToken(TokenTypeEnum::IDENTIFIER);

		if ($ident && $this->acceptSingleCharToken('.') && $this->acceptSingleCharToken('*')) {
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

	/** @phpstan-impure */
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

	/** @phpstan-impure */
	private function parseExpression(): Expr
	{
		$startPosition = $this->findCurrentToken()?->position
			?? throw new UnexpectedTokenException('Out of tokens');
		$ident = $this->acceptToken(TokenTypeEnum::IDENTIFIER);

		if ($ident) {
			if (! $this->acceptSingleCharToken('.')) {
				return new Column($startPosition, $ident->getEndPosition(), $this->cleanIdentifier($ident->content));
			}
		}

		$unaryOpToken = $this->acceptSingleCharToken('+')
			?? $this->acceptSingleCharToken('-')
			?? $this->acceptSingleCharToken('!')
			?? $this->acceptSingleCharToken('~');

		if ($unaryOpToken) {
			$expr = $this->parseExpression();

			return new UnaryOp($startPosition, UnaryOpTypeEnum::from($unaryOpToken->content), $expr);
		}

		$literalInt = $this->acceptToken(TokenTypeEnum::LITERAL_INT);

		if ($literalInt) {
			return new LiteralInt($startPosition, $literalInt->getEndPosition(), (int) $literalInt->content);
		}

		$literalFloat = $this->acceptToken(TokenTypeEnum::LITERAL_FLOAT);

		if ($literalFloat) {
			return new LiteralFloat($startPosition, $literalFloat->getEndPosition(), (float) $literalFloat->content);
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
	private function acceptSingleCharToken(string $char): ?Token
	{
		$token = $this->findCurrentToken();

		if ($token === null || $token->type !== TokenTypeEnum::SINGLE_CHAR || $token->content !== $char) {
			return null;
		}

		$this->position++;

		return $token;
	}

	/** @phpstan-impure */
	private function acceptToken(TokenTypeEnum $type): ?Token
	{
		$token = $this->findCurrentToken();

		if ($token?->type !== $type) {
			return null;
		}

		$this->position++;

		return $token;
	}

	/** @phpstan-impure */
	private function expectToken(TokenTypeEnum $type): Token
	{
		$token = $this->findCurrentToken();

		if ($token === null) {
			throw new UnexpectedTokenException("Expected {$type->value}, but reached end of token list.");
		}

		if ($token->type !== $type) {
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
}
