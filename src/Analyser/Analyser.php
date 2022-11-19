<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Database\FunctionInfo\FunctionInfoRegistry;
use MariaStan\DbReflection\DbReflection;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\MariaDbParser;

use function mb_substr;

final class Analyser
{
	public function __construct(
		private readonly MariaDbParser $parser,
		private readonly DbReflection $dbReflection,
		private readonly FunctionInfoRegistry $functionInfoRegistry,
	) {
	}

	/** @throws AnalyserException */
	public function analyzeQuery(string $query): AnalyserResult
	{
		try {
			$ast = $this->parser->parseSingleQuery($query);
		} catch (ParserException $e) {
			$queryShort = mb_substr($query, 0, 50);

			return new AnalyserResult(
				null,
				[new AnalyserError("Couldn't parse query: '{$queryShort}'. Error: {$e->getMessage()}")],
				null,
			);
		}

		return (new AnalyserState($this->dbReflection, $this->functionInfoRegistry, $ast, $query))->analyse();
	}
}
