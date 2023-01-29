<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

final class AnalyserColumnKnowledge
{
	// ($nullability = true) => column is always NULL, ($nullability = false) => column is never NULL
	public function __construct(public readonly ColumnInfo $columnInfo, public readonly bool $nullability)
	{
	}
}
