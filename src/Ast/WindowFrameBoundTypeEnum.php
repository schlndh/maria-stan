<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use function in_array;

enum WindowFrameBoundTypeEnum: string
{
	case EXPRESSION_PRECEDING = 'EXPR_PRECEDING';
	case EXPRESSION_FOLLOWING = 'EXPR_FOLLOWING';
	case CURRENT_ROW = 'CURRENT_ROW';
	case UNBOUNDED_PRECEDING = 'UNBOUNDED_PRECEDING';
	case UNBOUNDED_FOLLOWING = 'UNBOUNDED_FOLLOWING';

	public function isPreceding(): bool
	{
		return in_array($this, [self::EXPRESSION_PRECEDING, self::UNBOUNDED_PRECEDING], true);
	}

	public function isFollowing(): bool
	{
		return in_array($this, [self::EXPRESSION_FOLLOWING, self::UNBOUNDED_FOLLOWING], true);
	}
}
