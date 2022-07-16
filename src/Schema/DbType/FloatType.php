<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

final class FloatType implements DbType
{
	public static function getTypeEnum(): DbTypeEnum
	{
		return DbTypeEnum::FLOAT;
	}
}
