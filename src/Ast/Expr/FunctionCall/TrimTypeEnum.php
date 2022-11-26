<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

enum TrimTypeEnum: string
{
	case BOTH = 'BOTH';
	case LEADING = 'LEADING';
	case TRAILING = 'TRAILING';
}
