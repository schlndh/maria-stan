<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

enum DbTypeEnum: string
{
	/** @see IntType */
	case INT = 'INT';

	/** @see VarcharType */
	case VARCHAR = 'VARCHAR';

	/** @see MixedType */
	case MIXED = 'MIXED';
}
