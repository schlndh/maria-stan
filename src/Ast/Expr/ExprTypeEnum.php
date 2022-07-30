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

	/** @see LiteralString */
	case LITERAL_STRING = 'LITERAL_STRING';

	/** @see LiteralNull */
	case LITERAL_NULL = 'LITERAL_NULL';

	/** @see UnaryOp */
	case UNARY_OP = 'UNARY_OP';

	/** @see BinaryOp */
	case BINARY_OP = 'BINARY_OP';

	/** @see FunctionCall */
	case FUNCTION_CALL = 'FUNCTION_CALL';
}
