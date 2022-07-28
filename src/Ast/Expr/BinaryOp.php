<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;

class BinaryOp extends BaseNode implements Expr
{
	public function __construct(
		public readonly BinaryOpTypeEnum $operation,
		public readonly Expr $left,
		public readonly Expr $right,
	) {
		parent::__construct($this->left->getStartPosition(), $this->right->getEndPosition());
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::BINARY_OP;
	}
}
