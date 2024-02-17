<?php

declare(strict_types=1);

namespace MariaStan\Schema;

final class Table
{
	/**
	 * @param non-empty-array<string, Column> $columns name => column
	 * @param array<string, ForeignKey> $foreignKeys name => foreign key
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $columns,
		public readonly array $foreignKeys = [],
	) {
	}
}
