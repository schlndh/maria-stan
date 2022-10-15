<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

/** Like {@see TimeUnitEnum} but without the composite units. */
enum NonCompositeTimeUnitEnum: string
{
	case MICROSECOND = 'MICROSECOND';
	case SECOND = 'SECOND';
	case MINUTE = 'MINUTE';
	case HOUR = 'HOUR';
	case DAY = 'DAY';
	case WEEK = 'WEEK';
	case MONTH = 'MONTH';
	case QUARTER = 'QUARTER';
	case YEAR = 'YEAR';
}
