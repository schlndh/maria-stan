<?php

declare(strict_types=1);

namespace MariaStan\Ast;

enum WindowFrameTypeEnum: string
{
	case ROWS = 'ROWS';
	case RANGE = 'RANGE';
}
