<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper\MySQLi;

use MariaStan\Analyser\AnalyserResult;

final class QueryPrepareCallResult
{
	/** @param array<string> $errors */
	public function __construct(public readonly array $errors, public readonly ?AnalyserResult $analyserResult)
	{
	}
}
