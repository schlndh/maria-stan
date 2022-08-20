<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

enum DbTypeEnum: string
{
	/** @see IntType */
	case INT = 'INT';

	/** @see VarcharType */
	case VARCHAR = 'VARCHAR';

	/** @see DecimalType */
	case DECIMAL = 'DECIMAL';

	/** @see FloatType */
	case FLOAT = 'FLOAT';

	/** @see DateTimeType */
	case DATETIME = 'DATETIME';

	/** @see NullType */
	case NULL = 'NULL';

	/** @see EnumType */
	case ENUM = 'ENUM';

	/** @see MixedType */
	case MIXED = 'MIXED';
}
