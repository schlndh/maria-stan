<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class GroupByExpr extends BaseNode
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $expr,
		public readonly DirectionEnum $direction = DirectionEnum::ASC,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
