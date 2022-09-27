<?php

declare(strict_types=1);

namespace MariaStan\Ast\Lock;

enum SelectLockTypeEnum: string
{
	// FOR UPDATE
	case UPDATE = 'UPDATE';

	// LOCK IN SHARE MODE
	case SHARE = 'SHARE';
}
