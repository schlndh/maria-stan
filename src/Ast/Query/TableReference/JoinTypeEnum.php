<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

enum JoinTypeEnum: string
{
	case INNER_JOIN = 'INNER_JOIN';
	case LEFT_OUTER_JOIN = 'LEFT_OUTER_JOIN';
	case RIGHT_OUTER_JOIN = 'RIGHT_OUTER_JOIN';
	case CROSS_JOIN = 'CROSS_JOIN';

	public function isOuterJoin(): bool
	{
		return $this === self::LEFT_OUTER_JOIN || $this === self::RIGHT_OUTER_JOIN;
	}
}
