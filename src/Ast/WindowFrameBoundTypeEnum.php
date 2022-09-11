<?php

declare(strict_types=1);

namespace MariaStan\Ast;

enum WindowFrameBoundTypeEnum: string
{
	case EXPRESSION = 'EXPR';
	case CURRENT_ROW = 'CURRENT_ROW';
	case UNBOUNDED = 'UNBOUNDED';
}
