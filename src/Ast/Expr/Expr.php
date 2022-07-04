<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\Node;

interface Expr extends Node
{
	public static function getExprType(): ExprTypeEnum;
}
