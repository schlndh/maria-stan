<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class Tuple extends BaseNode implements Expr
{
	/** @param non-empty-array<Expr> $expressions */
	public function __construct(Position $startPosition, Position $endPosition, public readonly array $expressions)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::TUPLE;
	}
}
