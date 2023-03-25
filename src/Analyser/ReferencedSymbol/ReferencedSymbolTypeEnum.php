<?php

declare(strict_types=1);

namespace MariaStan\Analyser\ReferencedSymbol;

enum ReferencedSymbolTypeEnum: string
{
	/** @see Table */
	case TABLE = 'TABLE';

	/** @see TableColumn */
	case TABLE_COLUMN = 'TABLE_COLUMN';
}
