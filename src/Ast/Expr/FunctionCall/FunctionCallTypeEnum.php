<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

enum FunctionCallTypeEnum: string
{
	/** @see Cast */
	case CAST = 'CAST';

	/** @see Count */
	case COUNT = 'COUNT';

	/** @see GroupConcat */
	case GROUP_CONCAT = 'GROUP_CONCAT';

	/** @see JsonArrayAgg */
	case JSON_ARRAYAGG = 'JSON_ARRAYAGG';

	/** @see Position */
	case POSITION = 'POSITION';

	/** @see TimestampAddDiff */
	case TIMESTAMP_ADD_DIFF = 'TIMESTAMP_ADD_DIFF';

	/** @see StandardFunctionCall */
	case STANDARD = 'STANDARD';

	/** @see Window */
	case WINDOW = 'WINDOW';
}
