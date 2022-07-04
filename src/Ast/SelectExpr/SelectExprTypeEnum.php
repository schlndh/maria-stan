<?php

declare(strict_types=1);

namespace MariaStan\Ast\SelectExpr;

enum SelectExprTypeEnum: string
{
	/** @see AllColumns */
	case ALL_COLUMNS = 'ALL_COLUMNS';

	/** @see RegularExpr */
	case REGULAR_EXPR = 'REGULAR_EXPR';
}
