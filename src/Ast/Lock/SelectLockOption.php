<?php

declare(strict_types=1);

namespace MariaStan\Ast\Lock;

interface SelectLockOption
{
	public static function getSelectLockOptionType(): SelectLockOptionTypeEnum;
}
