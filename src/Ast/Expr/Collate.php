<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class Collate extends BaseNode implements Expr
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $expression,
		public readonly string $collation,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::COLLATE;
	}
}
