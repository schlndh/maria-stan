<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

final class SubqueryColumnInfo
{
	public function __construct(public readonly string $name, public readonly string $subqueryAlias)
	{
	}
}
