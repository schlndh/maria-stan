<?php

declare(strict_types=1);

namespace MariaStan\Ast\Lock;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class Wait extends BaseNode implements SelectLockOption
{
	public function __construct(Position $startPosition, Position $endPosition, public readonly float $seconds)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getSelectLockOptionType(): SelectLockOptionTypeEnum
	{
		return SelectLockOptionTypeEnum::WAIT;
	}
}
