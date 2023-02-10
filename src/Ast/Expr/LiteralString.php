<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

/** @implements LiteralExpr<string> */
final class LiteralString extends BaseNode implements LiteralExpr
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $value,
		// The first part of the string in cases where multiple literals are concatenated together.
		// E.g for "a" "b" it would be a. It's needed for name of the result field.
		public readonly string $firstConcatPart,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::LITERAL_STRING;
	}

	public function getLiteralValue(): mixed
	{
		return $this->value;
	}
}
