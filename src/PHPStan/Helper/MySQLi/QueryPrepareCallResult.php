<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper\MySQLi;

use MariaStan\Analyser\AnalyserResult;
use MariaStan\PHPStan\Helper\MariaStanError;

final class QueryPrepareCallResult
{
	/**
	 * @param list<MariaStanError> $errors
	 * @param list<AnalyserResult> $analyserResults
	 */
	public function __construct(public readonly array $errors, public readonly array $analyserResults)
	{
	}
}
