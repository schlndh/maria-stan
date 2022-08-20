<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

final class EnumType implements DbType
{
	/** @param array<string> $cases */
	public function __construct(public readonly array $cases)
	{
	}

	public static function getTypeEnum(): DbTypeEnum
	{
		return DbTypeEnum::ENUM;
	}
}
