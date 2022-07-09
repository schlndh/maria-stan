<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Schema\DbType\DbType;

final class QueryResultField
{
	public function __construct(
		public readonly string $name,
		public readonly DbType $type,
		public readonly bool $isNullable,
	) {
	}
}
