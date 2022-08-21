<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\Query\Query;
use MariaStan\Parser\Exception\ParserException;

use function array_filter;

class MariaDbParser
{
	private readonly MariaDbLexer $lexer;

	/** @var ?array<TokenTypeEnum> */
	private ?array $tableAliasTokenTypes = null;

	public function __construct()
	{
		$this->lexer = new MariaDbLexer();
	}

	/** @throws ParserException */
	public function parseSingleQuery(string $sqlQuery): Query
	{
		$tokens = $this->lexer->tokenize($sqlQuery);
		$parserState = new MariaDbParserState($this, $tokens);

		return $parserState->parseStrictSingleQuery();
	}

	/** @return array<TokenTypeEnum> */
	public function getTokenTypesWhichCanBeUsedAsUnquotedFieldAlias(): array
	{
		// https://mariadb.com/kb/en/reserved-words/#exceptions
		// Some of these are not on the list, but nevertheless they work (on MariaDB 10.6).
		return [
			TokenTypeEnum::IDENTIFIER,
			TokenTypeEnum::ACTION,
			TokenTypeEnum::BIT,
			TokenTypeEnum::DATE,
			TokenTypeEnum::ENUM,
			TokenTypeEnum::GENERAL,
			TokenTypeEnum::IGNORE_SERVER_IDS,
			TokenTypeEnum::MASTER_HEARTBEAT_PERIOD,
			TokenTypeEnum::NO,
			TokenTypeEnum::OPTION,
			TokenTypeEnum::POSITION,
			TokenTypeEnum::ROLLUP,
			TokenTypeEnum::SLOW,
			TokenTypeEnum::TEXT,
			TokenTypeEnum::TIME,
			TokenTypeEnum::TIMESTAMP,
			TokenTypeEnum::WINDOW,
		];
	}

	/** @return array<TokenTypeEnum> */
	public function getTokenTypesWhichCanBeUsedAsUnquotedTableAlias(): array
	{
		return $this->tableAliasTokenTypes ??= array_filter(
			$this->getTokenTypesWhichCanBeUsedAsUnquotedFieldAlias(),
			// From MariaDB 10.2.12 only disallowed for table aliases.
			static fn (TokenTypeEnum $t) => $t !== TokenTypeEnum::WINDOW,
		);
	}
}
