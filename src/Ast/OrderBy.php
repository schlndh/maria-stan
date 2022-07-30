<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Parser\Position;

final class OrderBy extends BaseNode
{
	/** @param non-empty-array<ExprWithDirection> $expressions */
	public function __construct(Position $startPosition, Position $endPosition, public readonly array $expressions)
	{
		parent::__construct($startPosition, $endPosition);
	}
}
