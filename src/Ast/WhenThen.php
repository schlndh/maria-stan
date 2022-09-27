<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class WhenThen extends BaseNode
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $when,
		public readonly Expr $then,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
