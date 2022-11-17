<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper\MySQLi;

use MariaStan\Analyser\AnalyserResult;

final class QueryPrepareCallResult
{
	/**
	 * @param array<string> $errors
	 * @param array<AnalyserResult> $analyserResults
	 */
	public function __construct(public readonly array $errors, public readonly array $analyserResults)
	{
	}
}
