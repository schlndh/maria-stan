<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

enum FunctionCallTypeEnum: string
{
	/** @see StandardFunctionCall */
	case STANDARD = 'STANDARD';

	/** @see Count */
	case COUNT = 'COUNT';
}
