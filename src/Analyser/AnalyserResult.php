<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\ReferencedSymbol\ReferencedSymbol;

final class AnalyserResult
{
	/**
	 * @param ?array<QueryResultField> $resultFields fields in the order they are returned by query, null = no analysis
	 * @param list<AnalyserError> $errors
	 * @param ?list<ReferencedSymbol> $referencedSymbols null = no analysis
	 */
	public function __construct(
		public readonly ?array $resultFields,
		public readonly array $errors,
		public readonly ?int $positionalPlaceholderCount,
		public readonly ?array $referencedSymbols,
		public readonly ?QueryResultRowCountRange $rowCountRange,
	) {
	}
}
