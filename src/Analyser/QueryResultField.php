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

	public function getRenamed(string $newName): self
	{
		return new self($newName, $this->type, $this->isNullable);
	}
}
