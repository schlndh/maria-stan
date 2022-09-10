<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\ExprTypeEnum;

abstract class BaseFunctionCall extends BaseNode implements FunctionCall
{
	final public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::FUNCTION_CALL;
	}
}
