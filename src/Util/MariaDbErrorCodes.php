<?php

declare(strict_types=1);

namespace MariaStan\Util;

/** @see https://mariadb.com/kb/en/mariadb-error-codes/ */
class MariaDbErrorCodes
{
	// 1052	23000	ER_NON_UNIQ_ERROR	Column '%s' in %s is ambiguous
	public const ER_NON_UNIQ_ERROR = 1052;

	// 1054	42S22	ER_BAD_FIELD_ERROR	Unknown column '%s' in '%s'
	public const ER_BAD_FIELD_ERROR = 1054;

	// 1060	42S21	ER_DUP_FIELDNAME	Duplicate column name '%s'
	public const ER_DUP_FIELDNAME = 1060;

	// 1066	42000	ER_NONUNIQ_TABLE	Not unique table/alias: '%s'
	public const ER_NONUNIQ_TABLE = 1066;

	// 1146	42S02	ER_NO_SUCH_TABLE	Table '%s.%s' doesn't exist
	public const ER_NO_SUCH_TABLE = 1146;

	// 1241	21000	ER_OPERAND_COLUMNS	Operand should contain %d column(s)
	public const ER_OPERAND_COLUMNS = 1241;

	// 4078	ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION	Illegal parameter data types %s and %s for operation '%s'
	public const ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION = 4078;

	// 4079	ER_ILLEGAL_PARAMETER_DATA_TYPE_FOR_OPERATION	Illegal parameter data type %s for operation '%s'
	public const ER_ILLEGAL_PARAMETER_DATA_TYPE_FOR_OPERATION = 4079;
}
