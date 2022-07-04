<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

final class Table implements TableReference
{
	public function __construct(public readonly string $name, public readonly ?string $alias = null)
	{
	}

	public static function getTableReferenceType(): TableReferenceTypeEnum
	{
		return TableReferenceTypeEnum::TABLE;
	}
}
