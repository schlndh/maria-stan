<?php

declare(strict_types=1);

namespace MariaStan\Schema;

final class ForeignKey
{
	/**
	 * @param non-empty-list<string> $columnNames
	 * @param non-empty-list<string> $referencedColumnNames
	 */
	public function __construct(
		public readonly string $constraintName,
		public readonly string $tableName,
		public readonly array $columnNames,
		public readonly string $referencedDatabaseName,
		public readonly string $referencedTableName,
		public readonly array $referencedColumnNames,
	) {
	}
}
