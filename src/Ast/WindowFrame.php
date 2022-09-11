<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Parser\Position;

final class WindowFrame extends BaseNode
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly WindowFrameTypeEnum $type,
		public readonly WindowFrameBound $preceding,
		public readonly ?WindowFrameBound $following,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
