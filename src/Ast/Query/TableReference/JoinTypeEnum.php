<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

enum JoinTypeEnum: string
{
	case INNER_JOIN = 'INNER_JOIN';
	case LEFT_OUTER_JOIN = 'LEFT_OUTER_JOIN';
	case RIGHT_OUTER_JOIN = 'RIGHT_OUTER_JOIN';
	case CROSS_JOIN = 'CROSS_JOIN';
}
