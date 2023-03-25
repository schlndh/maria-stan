<?php

declare(strict_types=1);

namespace MariaStan\Analyser\ReferencedSymbol;

final class TableColumn implements ReferencedSymbol
{
	public function __construct(public readonly Table $table, public readonly string $name)
	{
	}

	public static function getReferencedSymbolType(): ReferencedSymbolTypeEnum
	{
		return ReferencedSymbolTypeEnum::TABLE_COLUMN;
	}
}
