<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\CastType;

enum CastTypeEnum: string
{
	/** @see BinaryCastType */
	case BINARY = 'BINARY';

	/** @see CharCastType */
	case CHAR = 'CHAR';

	/** @see DateCastType */
	case DATE = 'DATE';

	/** @see DateTimeCastType */
	case DATETIME = 'DATETIME';

	/** @see DecimalCastType */
	case DECIMAL = 'DECIMAL';

	/** @see DoubleCastType */
	case DOUBLE = 'DOUBLE';

	/** @see FloatCastType */
	case FLOAT = 'FLOAT';

	/** @see IntegerCastType */
	case INTEGER = 'INTEGER';

	/** @see TimeCastType */
	case TIME = 'TIME';

	/** @see DaySecondCastType */
	case DAY_SECOND = 'DAY_SECOND';
}
