<?php

declare(strict_types=1);

namespace MariaStan\Ast\Lock;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class SelectLock extends BaseNode
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly SelectLockTypeEnum $type,
		public readonly ?SelectLockOption $lockOption = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
