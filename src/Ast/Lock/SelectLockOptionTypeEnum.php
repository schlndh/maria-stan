<?php

declare(strict_types=1);

namespace MariaStan\Ast\Lock;

enum SelectLockOptionTypeEnum: string
{
	/** @see Wait */
	case WAIT = 'WAIT';

	/** @see NoWait */
	case NOWAIT = 'NOWAIT';

	/** @see SkipLocked */
	case SKIP_LOCKED = 'SKIP_LOCKED';
}
