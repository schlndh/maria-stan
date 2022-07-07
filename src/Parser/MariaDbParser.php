<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\Query\Query;
use MariaStan\Parser\Exception\ParserException;

class MariaDbParser
{
	private readonly MariaDbLexer $lexer;

	public function __construct()
	{
		$this->lexer = new MariaDbLexer();
	}

	/** @throws ParserException */
	public function parseSingleQuery(string $sqlQuery): Query
	{
		$tokens = $this->lexer->tokenize($sqlQuery);
		$parserState = new MariaDbParserState($tokens);

		return $parserState->parseStrictSingleQuery();
	}
}
