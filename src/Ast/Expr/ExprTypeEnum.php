<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

enum ExprTypeEnum: string
{
	/** @see Column */
	case COLUMN = 'COLUMN';

	/** @see LiteralInt */
	case LITERAL_INT = 'LITERAL_INT';

	/** @see LiteralFloat */
	case LITERAL_FLOAT = 'LITERAL_FLOAT';

	/** @see UnaryOp */
	case UNARY_OP = 'UNARY_OP';
}
