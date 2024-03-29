<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

final class DecimalType implements DbType
{
	public static function getTypeEnum(): DbTypeEnum
	{
		return DbTypeEnum::DECIMAL;
	}
}
