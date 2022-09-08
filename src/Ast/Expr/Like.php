<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;

final class Like extends BaseNode implements Expr
{
	public function __construct(
		public readonly Expr $expression,
		public readonly Expr $pattern,
		public readonly ?Expr $escapeChar,
	) {
		parent::__construct(
			$this->expression->getStartPosition(),
			$this->escapeChar?->getEndPosition() ?? $this->pattern->getEndPosition(),
		);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::LIKE;
	}
}
