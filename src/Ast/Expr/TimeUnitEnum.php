<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

enum TimeUnitEnum: string
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
	case SECOND_MICROSECOND = 'SECOND_MICROSECOND';
	case MINUTE_MICROSECOND = 'MINUTE_MICROSECOND';
	case MINUTE_SECOND = 'MINUTE_SECOND';
	case HOUR_MICROSECOND = 'HOUR_MICROSECOND';
	case HOUR_SECOND = 'HOUR_SECOND';
	case HOUR_MINUTE = 'HOUR_MINUTE';
	case DAY_MICROSECOND = 'DAY_MICROSECOND';
	case DAY_SECOND = 'DAY_SECOND';
	case DAY_MINUTE = 'DAY_MINUTE';
	case DAY_HOUR = 'DAY_HOUR';
	case YEAR_MONTH = 'YEAR_MONTH';
}
