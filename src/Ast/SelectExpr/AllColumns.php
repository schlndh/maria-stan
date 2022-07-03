<?php

declare(strict_types=1);

namespace MariaStan\Ast\SelectExpr;

use MariaStan\Ast\SelectExpr;

final class AllColumns implements SelectExpr
{
	public function __construct(public readonly ?string $tableName = null)
	{
	}
}
