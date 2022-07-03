<?php

declare(strict_types=1);

namespace MariaStan\Ast\SelectExpr;

use MariaStan\Ast\Node;

final class AllColumns implements Node
{
	public function __construct(public readonly ?string $tableName = null)
	{
	}
}
