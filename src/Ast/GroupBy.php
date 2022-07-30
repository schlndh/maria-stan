<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Parser\Position;

final class GroupBy extends BaseNode
{
	/** @param array<GroupByExpr> $expressions */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly array $expressions,
		public readonly bool $isWithRollup = false,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
