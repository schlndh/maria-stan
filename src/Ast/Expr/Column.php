<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\Expr;

class Column implements Expr
{
	public function __construct(public readonly string $name, public readonly ?string $tableName = null)
	{
	}
}
