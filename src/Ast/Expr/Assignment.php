<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class Assignment extends BaseNode implements Expr
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		// TODO: variables
		public readonly Column $target,
		public readonly Expr $expression,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::ASSIGNMENT;
	}
}
