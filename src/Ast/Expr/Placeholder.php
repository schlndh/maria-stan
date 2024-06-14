<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class Placeholder extends BaseNode implements Expr
{
	/**
	 * @param int|string $name Int is for positional placeholders (starting from 1). String is not currently used,
	 * but it is reserved for PDO-style named placeholders.
	 */
	public function __construct(Position $startPosition, Position $endPosition, public readonly int|string $name)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::PLACEHOLDER;
	}
}
