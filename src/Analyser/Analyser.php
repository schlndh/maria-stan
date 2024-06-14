<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\PlaceholderTypeProvider\MixedPlaceholderTypeProvider;
use MariaStan\Analyser\PlaceholderTypeProvider\PlaceholderTypeProvider;
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

	/**
	 * @param ?PlaceholderTypeProvider $placeholderTypeProvider Pass PlaceholderTypeProvider to narrow down placeholder
	 * 	types. If you pass null, then all placeholders will get nullable mixed type. If you bind all placeholders as
	 * 	strings, you can pass {@see VarcharPlaceholderTypeProvider}.
	 * @throws AnalyserException
	 */
	public function analyzeQuery(
		string $query,
		?PlaceholderTypeProvider $placeholderTypeProvider = null,
	): AnalyserResult {
		try {
			$ast = $this->parser->parseSingleQuery($query);
		} catch (ParserException $e) {
			$queryShort = mb_substr($query, 0, 50);

			return new AnalyserResult(
				null,
				[
					new AnalyserError(
						"Couldn't parse query: '{$queryShort}'. Error: {$e->getMessage()}",
						AnalyserErrorTypeEnum::PARSE,
					),
				],
				null,
				null,
				null,
			);
		}

		$placeholderTypeProvider ??= new MixedPlaceholderTypeProvider();

		return (new AnalyserState(
			$this->dbReflection,
			$this->functionInfoRegistry,
			$placeholderTypeProvider,
			$ast,
			$query,
		))->analyse();
	}
}
