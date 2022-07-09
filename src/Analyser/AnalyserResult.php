<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

final class AnalyserResult
{
	/**
	 * @param array<QueryResultField> $resultFields fields in the order they are returned by query
	 * @param array<AnalyserError> $errors
	 */
	public function __construct(public readonly array $resultFields, public readonly array $errors)
	{
	}
}
