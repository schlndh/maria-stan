<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;

final class Between extends BaseNode implements Expr
{
	public function __construct(public readonly Expr $expression, public readonly Expr $min, public readonly Expr $max)
	{
		parent::__construct($this->expression->getStartPosition(), $max->getEndPosition());
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::BETWEEN;
	}
}
