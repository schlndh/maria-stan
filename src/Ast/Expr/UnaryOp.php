<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class UnaryOp extends BaseNode implements Expr
{
	public function __construct(
		Position $startPosition,
		public readonly UnaryOpTypeEnum $operation,
		public readonly Expr $expression,
	) {
		parent::__construct($startPosition, $this->expression->getEndPosition());
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::UNARY_OP;
	}
}
