<?php

declare(strict_types=1);

namespace MariaStan\Schema;

final class Table
{
	/**
	 * @param ?string $database NULL for CTE
	 * @param non-empty-array<int|string, Column> $columns name => column
	 * @param array<int|string, ForeignKey> $foreignKeys name => foreign key
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $database,
		public readonly array $columns,
		public readonly array $foreignKeys = [],
	) {
	}
}
