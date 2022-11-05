<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

class InformationSchemaColumn
{
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly bool $isNullable,
		public readonly ?string $default,
		public readonly string $extra,
	) {
	}
}
