<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Ast\Query\QueryTypeEnum;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\MariaDbParser;

use function assert;

final class Analyser
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
				null,
				[new AnalyserError("Couldn't parse query: {$e->getMessage()}")],
				null,
			);
		}

		if ($ast::getQueryType() !== QueryTypeEnum::SELECT) {
			return new AnalyserResult(
				null,
				[new AnalyserError("Unsupported query: {$ast::getQueryType()->value}")],
				null,
			);
		}

		assert($ast instanceof SelectQuery);

		return (new SelectAnalyser($this->dbReflection, $ast, $query))->analyse();
	}
}
