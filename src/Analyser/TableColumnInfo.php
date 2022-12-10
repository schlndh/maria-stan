<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

final class TableColumnInfo
{
	public function __construct(
		public readonly string $name,
		public readonly string $tableName,
		public readonly string $tableAlias,
	) {
	}
}
