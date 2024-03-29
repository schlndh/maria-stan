<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

enum ExprTypeEnum: string
{
	/** @see Assignment */
	case ASSIGNMENT = 'ASSIGNMENT';

	/** @see Column */
	case COLUMN = 'COLUMN';

	/** @see Placeholder */
	case PLACEHOLDER = 'PLACEHOLDER';

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

	/** @see CaseOp */
	case CASE_OP = 'CASE_OP';

	/** @see \MariaStan\Ast\Expr\FunctionCall\FunctionCall */
	case FUNCTION_CALL = 'FUNCTION_CALL';

	/** @see Tuple */
	case TUPLE = 'TUPLE';

	/** @see Subquery */
	case SUBQUERY = 'SUBQUERY';

	/** @see Between */
	case BETWEEN = 'BETWEEN';

	/** @see Is */
	case IS = 'IS';

	/** @see In */
	case IN = 'IN';

	/** @see Like */
	case LIKE = 'LIKE';

	/** @see Exists */
	case EXISTS = 'EXISTS';

	/** @see Interval */
	case INTERVAL = 'INTERVAL';

	/** @see CastType\CastType */
	case CAST_TYPE = 'CAST_TYPE';

	/** @see ColumnDefault */
	case COLUMN_DEFAULT = 'COLUMN_DEFAULT';

	/** @see Collate */
	case COLLATE = 'COLLATE';
}
