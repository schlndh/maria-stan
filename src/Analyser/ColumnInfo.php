<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\Exception\ShouldNotHappenException;

final class ColumnInfo
{
	public function __construct(
		public readonly string $name,
		public readonly string $tableName,
		public readonly string $tableAlias,
		public readonly ColumnInfoTableTypeEnum $tableType,
		// TODO: check that it's set everywhere
		public readonly ?string $database,
	) {
		if ($this->tableType === ColumnInfoTableTypeEnum::SUBQUERY && $this->database !== null) {
			throw new ShouldNotHappenException('Subquery cannot have database name set.');
		}
	}
}
