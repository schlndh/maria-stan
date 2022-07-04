<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

final class AnalyserError
{
	public function __construct(public readonly string $message)
	{
	}
}
