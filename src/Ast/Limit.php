<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class Limit extends BaseNode
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $count,
		public readonly ?Expr $offset = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
