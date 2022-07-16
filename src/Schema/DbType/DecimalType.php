<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

class DecimalType implements DbType
{
	public static function getTypeEnum(): DbTypeEnum
	{
		return DbTypeEnum::DECIMAL;
	}
}
