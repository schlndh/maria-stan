<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

enum BinaryOpTypeEnum: string
{
	case PLUS = '+';
	case MINUS = '-';
	case MULTIPLICATION = '*';
	case DIVISION = '/';
	case INT_DIVISION = 'DIV';
	case MODULO = '%';
}
