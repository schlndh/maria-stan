<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class WindowFrameBound extends BaseNode
{
	private function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly WindowFrameBoundTypeEnum $type,
		// MariaDB currently parses/supports only a very limited number of expressions here. But that could change
		// in future versions. For example MySQL supports placeholders and temporal intervals.
		public readonly ?Expr $expression,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function createCurrentRow(Position $startPosition, Position $endPosition): self
	{
		return new self($startPosition, $endPosition, WindowFrameBoundTypeEnum::CURRENT_ROW, null);
	}

	public static function createUnbounded(Position $startPosition, Position $endPosition): self
	{
		return new self($startPosition, $endPosition, WindowFrameBoundTypeEnum::UNBOUNDED, null);
	}

	public static function createExpression(Position $startPosition, Position $endPosition, Expr $expression): self
	{
		return new self($startPosition, $endPosition, WindowFrameBoundTypeEnum::EXPRESSION, $expression);
	}
}
