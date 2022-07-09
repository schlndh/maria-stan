<?php

declare(strict_types=1);

namespace MariaStan\Ast\SelectExpr;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class RegularExpr extends BaseNode implements SelectExpr
{
	public function __construct(
		Position $endPosition,
		public readonly Expr $expr,
		public readonly ?string $alias = null,
	) {
		parent::__construct($this->expr->getStartPosition(), $endPosition);
	}

	public static function getSelectExprType(): SelectExprTypeEnum
	{
		return SelectExprTypeEnum::REGULAR_EXPR;
	}
}
