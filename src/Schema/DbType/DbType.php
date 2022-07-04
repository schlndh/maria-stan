<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

interface DbType
{
	public static function getTypeEnum(): DbTypeEnum;
}
