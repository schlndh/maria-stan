<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

final class DateTimeType implements DbType
{
	public static function getTypeEnum(): DbTypeEnum
	{
		return DbTypeEnum::DATETIME;
	}
}
