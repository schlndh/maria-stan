<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

/** @implements LiteralExpr<null> */
final class LiteralNull extends BaseNode implements LiteralExpr
{
	public function __construct(Position $startPosition, Position $endPosition)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::LITERAL_NULL;
	}

	public function getLiteralValue(): mixed
	{
		return null;
	}
}
