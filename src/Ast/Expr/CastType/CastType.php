<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\CastType;

use MariaStan\Ast\Expr\Expr;

interface CastType extends Expr
{
	public static function getCastType(): CastTypeEnum;
}
