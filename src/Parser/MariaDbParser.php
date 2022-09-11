<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\Query\Query;
use MariaStan\Parser\Exception\ParserException;

use function array_filter;
use function array_values;

class MariaDbParser
{
	private readonly MariaDbLexer $lexer;

	/** @var ?array<TokenTypeEnum> */
	private ?array $tableAliasTokenTypes = null;

	/** @var ?array<TokenTypeEnum> */
	private ?array $identifierAfterDotTokenTypes = null;

	public function __construct()
	{
		$this->lexer = new MariaDbLexer();
	}

	/** @throws ParserException */
	public function parseSingleQuery(string $sqlQuery): Query
	{
		$tokens = $this->lexer->tokenize($sqlQuery);
		$parserState = new MariaDbParserState($this, $sqlQuery, $tokens);

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
			TokenTypeEnum::CURRENT,
			TokenTypeEnum::DATE,
			TokenTypeEnum::ENUM,
			TokenTypeEnum::ESCAPE,
			TokenTypeEnum::FOLLOWING,
			TokenTypeEnum::GENERAL,
			TokenTypeEnum::IGNORE_SERVER_IDS,
			TokenTypeEnum::MASTER_HEARTBEAT_PERIOD,
			TokenTypeEnum::NO,
			TokenTypeEnum::OPTION,
			TokenTypeEnum::POSITION,
			TokenTypeEnum::PRECEDING,
			TokenTypeEnum::ROLLUP,
			TokenTypeEnum::ROW,
			TokenTypeEnum::SLOW,
			TokenTypeEnum::TEXT,
			TokenTypeEnum::TIME,
			TokenTypeEnum::TIMESTAMP,
			TokenTypeEnum::UNBOUNDED,
			TokenTypeEnum::UNKNOWN,
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

	/** @return array<TokenTypeEnum> */
	public function getExplicitTokenTypesForFunctions(): array
	{
		return [
			TokenTypeEnum::CURRENT_DATE,
			TokenTypeEnum::CURRENT_ROLE,
			TokenTypeEnum::CURRENT_TIME,
			TokenTypeEnum::CURRENT_TIMESTAMP,
			TokenTypeEnum::CURRENT_USER,
			TokenTypeEnum::LOCALTIME,
			TokenTypeEnum::LOCALTIMESTAMP,
			TokenTypeEnum::UTC_DATE,
			TokenTypeEnum::UTC_TIME,
			TokenTypeEnum::UTC_TIMESTAMP,
		];
	}

	/** @return array<string> uppercase names */
	public function getFunctionsThatSupportDistinct(): array
	{
		return [
			'AVG',
			'MAX',
			'MIN',
			'SUM',
			// Also COUNT, GROUP_CONCAT and JSON_ARRAYAGG but those have to be handled separately.
		];
	}

	/** @return array<string> uppercase names */
	public function getWindowFunctions(): array
	{
		return [
			'AVG',
			'BIT_AND',
			'BIT_OR',
			'BIT_XOR',
			'COUNT',
			'CUME_DIST',
			'DENSE_RANK',
			'FIRST_VALUE',
			'LAG',
			'LAST_VALUE',
			'LEAD',
			'MAX',
			'MEDIAN',
			'MIN',
			'NTILE',
			'NTH_VALUE',
			'PERCENT_RANK',
			'RANK',
			'ROW_NUMBER',
			'NTILE',
			'STD',
			'STDDEV',
			'STDDEV_POP',
			'STDDEV_SAMP',
			'SUM',
			'VARIANCE',
			'VAR_POP',
			'VAR_SAMP',
			// PERCENTILE_DISC and PERCENTILE_CONT will have to be handled separately because of their special syntax.
		];
	}

	/** @return array<TokenTypeEnum> */
	public function getTokenTypesWhichCanBeUsedAsUnquotedIdentifierAfterDot(): array
	{
		if ($this->identifierAfterDotTokenTypes === null) {
			$this->identifierAfterDotTokenTypes = array_values(TokenTypeEnum::getKeywordsMap());
			$this->identifierAfterDotTokenTypes[] = TokenTypeEnum::IDENTIFIER;
		}

		return $this->identifierAfterDotTokenTypes;
	}
}
