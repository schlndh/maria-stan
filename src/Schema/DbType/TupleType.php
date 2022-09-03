<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

use function count;

final class TupleType implements DbType
{
	public readonly int $typeCount;

	/** @param array<DbType> $types */
	public function __construct(public readonly array $types, public readonly bool $isFromSubquery)
	{
		$this->typeCount = count($this->types);
	}

	public static function getTypeEnum(): DbTypeEnum
	{
		return DbTypeEnum::TUPLE;
	}
}
