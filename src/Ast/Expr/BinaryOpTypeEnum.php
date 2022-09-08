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
	case LOGIC_OR = 'OR';
	case LOGIC_XOR = 'XOR';
	case LOGIC_AND = 'AND';
	case EQUAL = '=';
	case NULL_SAFE_EQUAL = '<=>';
	case GREATER_OR_EQUAL = '>=';
	case GREATER = '>';
	case LOWER_OR_EQUAL = '<=';
	case LOWER = '<';
	case NOT_EQUAL = '!=';
	case REGEXP = 'REGEXP';
	case BITWISE_OR = '|';
	case BITWISE_AND = '&';
	case SHIFT_LEFT = '<<';
	case SHIFT_RIGHT = '>>';
	case BITWISE_XOR = '^';
	// TODO: COLLATE
}
