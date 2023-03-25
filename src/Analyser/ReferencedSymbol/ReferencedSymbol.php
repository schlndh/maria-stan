<?php

declare(strict_types=1);

namespace MariaStan\Analyser\ReferencedSymbol;

interface ReferencedSymbol
{
	public static function getReferencedSymbolType(): ReferencedSymbolTypeEnum;
}
