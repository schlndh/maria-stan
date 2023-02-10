<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

/** @template T of scalar|null */
interface LiteralExpr extends Expr
{
	/** @return T */
	public function getLiteralValue(): mixed;
}
