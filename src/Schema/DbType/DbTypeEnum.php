<?php

declare(strict_types=1);

namespace MariaStan\Schema\DbType;

enum DbTypeEnum: string
{
	/** @see IntType */
	case INT = 'INT';

	/** @see UnsignedIntType */
	case UNSIGNED_INT = 'UNSIGNED_INT';

	/** @see VarcharType */
	case VARCHAR = 'VARCHAR';

	/** @see DecimalCastType */
	case DECIMAL = 'DECIMAL';

	/** @see FloatCastType */
	case FLOAT = 'FLOAT';

	/** @see DateTimeCastType */
	case DATETIME = 'DATETIME';

	/** @see NullType */
	case NULL = 'NULL';

	/** @see EnumType */
	case ENUM = 'ENUM';

	/** @see TupleType */
	case TUPLE = 'TUPLE';

	/** @see MixedType */
	case MIXED = 'MIXED';
}
