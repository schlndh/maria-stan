<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class Interval extends BaseNode implements Expr
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $timeQuantity,
		public readonly TimeUnitEnum $timeUnit,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::INTERVAL;
	}
}
