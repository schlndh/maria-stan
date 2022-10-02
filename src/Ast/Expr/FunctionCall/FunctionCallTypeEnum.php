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

	/** @see Position */
	case POSITION = 'POSITION';

	/** @see StandardFunctionCall */
	case STANDARD = 'STANDARD';

	/** @see Window */
	case WINDOW = 'WINDOW';
}
