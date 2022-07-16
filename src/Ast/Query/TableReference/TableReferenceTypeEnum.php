<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

enum TableReferenceTypeEnum: string
{
	/** @see Table */
	case TABLE = 'TABLE';

	/** @see Join */
	case JOIN = 'JOIN';
}
