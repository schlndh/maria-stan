<?php

declare(strict_types=1);

namespace MariaStan\Analyser\ReferencedSymbol;

final class Table implements ReferencedSymbol
{
	public function __construct(public readonly string $name, public readonly string $database)
	{
	}

	public static function getReferencedSymbolType(): ReferencedSymbolTypeEnum
	{
		return ReferencedSymbolTypeEnum::TABLE;
	}
}
