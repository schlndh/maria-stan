<?php

declare(strict_types=1);

namespace MariaStan\Ast\SelectExpr;

use MariaStan\Ast\Expr\Expr;

final class RegularExpr implements SelectExpr
{
	public function __construct(public readonly Expr $expr, public readonly ?string $alias = null)
	{
	}

	public static function getSelectExprType(): SelectExprTypeEnum
	{
		return SelectExprTypeEnum::REGULAR_EXPR;
	}
}
