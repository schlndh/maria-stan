<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\CastType;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\ExprTypeEnum;

abstract class BaseCastType extends BaseNode implements CastType
{
	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::CAST_TYPE;
	}
}
