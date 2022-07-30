<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class FunctionCall extends BaseNode implements Expr
{
	/** @param array<Expr> $arguments */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $name,
		public readonly array $arguments,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::FUNCTION_CALL;
	}
}
