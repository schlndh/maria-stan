<?php

declare(strict_types=1);

namespace MariaStan\Schema;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Schema\DbType\DbType;

final class Column
{
	public function __construct(
		public readonly string $name,
		public readonly DbType $type,
		public readonly bool $isNullable,
		public readonly ?Expr $defaultValue = null,
		public readonly bool $isAutoIncrement = false,
	) {
	}
}
