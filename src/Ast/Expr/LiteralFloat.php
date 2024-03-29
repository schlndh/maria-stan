<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

/** @implements LiteralExpr<float> */
final class LiteralFloat extends BaseNode implements LiteralExpr
{
	public function __construct(Position $startPosition, Position $endPosition, public readonly float $value)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::LITERAL_FLOAT;
	}

	public function getLiteralValue(): mixed
	{
		return $this->value;
	}
}
