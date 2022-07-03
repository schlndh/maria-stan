<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\Expr;
use MariaStan\Ast\Query;
use MariaStan\Ast\SelectExpr;

final class SelectQuery implements Query
{
	/**
	 * @param non-empty-array<Expr|SelectExpr> $select
	 * @param array<TableReference>|null $from
	 */
	public function __construct(public readonly array $select, public readonly ?array $from)
	{
	}
}
