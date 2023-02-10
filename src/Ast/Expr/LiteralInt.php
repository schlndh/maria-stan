<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

/** @implements LiteralExpr<int> */
final class LiteralInt extends BaseNode implements LiteralExpr
{
	public function __construct(Position $startPosition, Position $endPosition, public readonly int $value)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::LITERAL_INT;
	}

	public function getLiteralValue(): mixed
	{
		return $this->value;
	}
}
