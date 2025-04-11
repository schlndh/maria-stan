<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\Position;
use MariaStan\Parser\TokenTypeEnum;

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

	/** @throws ParserException */
	public static function createUnbounded(
		Position $startPosition,
		Position $endPosition,
		TokenTypeEnum $direction,
	): self {
		return new self(
			$startPosition,
			$endPosition,
			self::isPreceding($direction)
				? WindowFrameBoundTypeEnum::UNBOUNDED_PRECEDING
				: WindowFrameBoundTypeEnum::UNBOUNDED_FOLLOWING,
			null,
		);
	}

	/** @throws ParserException */
	public static function createExpression(
		Position $startPosition,
		Position $endPosition,
		Expr $expression,
		TokenTypeEnum $direction,
	): self {
		return new self(
			$startPosition,
			$endPosition,
			self::isPreceding($direction)
				? WindowFrameBoundTypeEnum::EXPRESSION_PRECEDING
				: WindowFrameBoundTypeEnum::EXPRESSION_FOLLOWING,
			$expression,
		);
	}

	/** @throws ParserException */
	private static function isPreceding(TokenTypeEnum $direction): bool
	{
		return match ($direction) {
			TokenTypeEnum::PRECEDING => true,
			TokenTypeEnum::FOLLOWING => false,
			default => throw new ParserException('Expected PRECEDING/FOLLOWING got ' . $direction->value),
		};
	}
}
