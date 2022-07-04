<?php

declare(strict_types=1);

namespace MariaStan\Schema;

final class Table
{
	/** @param array<Column> $columns */
	public function __construct(public readonly string $name, public readonly array $columns)
	{
	}
}
