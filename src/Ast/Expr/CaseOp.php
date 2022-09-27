<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\WhenThen;
use MariaStan\Parser\Position;

final class CaseOp extends BaseNode implements Expr
{
	/** @param non-empty-array<WhenThen> $conditions */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly ?Expr $compareValue,
		public readonly array $conditions,
		public readonly ?Expr $else,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::CASE_OP;
	}
}
