<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

enum UnaryOpTypeEnum: string
{
	case PLUS = '+';
	case MINUS = '-';
	case LOGIC_NOT = '!';
	case BITWISE_NOT = '~';
	case BINARY = 'BINARY';
}
