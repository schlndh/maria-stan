<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

/** @template-covariant TValue */
final class ResultWithWarnings
{
	/**
	 * @param TValue $result
	 * @param array<AnalyserError> $warnings
	 */
	public function __construct(public readonly mixed $result, public readonly array $warnings)
	{
	}
}
