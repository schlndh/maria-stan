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

	// 1064	42000	ER_PARSE_ERROR	%s near '%s' at line %d
	public const ER_PARSE_ERROR = 1064;

	// 1066	42000	ER_NONUNIQ_TABLE	Not unique table/alias: '%s'
	public const ER_NONUNIQ_TABLE = 1066;

	// 1146	42S02	ER_NO_SUCH_TABLE	Table '%s.%s' doesn't exist
	public const ER_NO_SUCH_TABLE = 1146;

	// 1210	HY000	ER_WRONG_ARGUMENTS	Incorrect arguments to %s
	public const ER_WRONG_ARGUMENTS = 1210;

	// 1221	HY000	ER_WRONG_USAGE	Incorrect usage of %s and %s
	public const ER_WRONG_USAGE = 1221;

	// 1222	21000	ER_WRONG_NUMBER_OF_COLUMNS_IN_SELECT The used SELECT statements have a different number of columns
	public const ER_WRONG_NUMBER_OF_COLUMNS_IN_SELECT = 1222;

	// 1241	21000	ER_OPERAND_COLUMNS	Operand should contain %d column(s)
	public const ER_OPERAND_COLUMNS = 1241;

	// 1247	42S22	ER_ILLEGAL_REFERENCE	Reference '%s' not supported (%s)
	public const ER_ILLEGAL_REFERENCE = 1247;

	// 1250	42000	ER_TABLENAME_NOT_ALLOWED_HERE	Table '%s' from one of the SELECTs cannot be used in %s
	public const ER_TABLENAME_NOT_ALLOWED_HERE = 1250;

	// 1582	42000	ER_WRONG_PARAMCOUNT_TO_NATIVE_FCT	Incorrect parameter count in the call to native function '%s'
	public const ER_WRONG_PARAMCOUNT_TO_NATIVE_FCT = 1582;

	// 1630	42000 ER_FUNC_INEXISTENT_NAME_COLLISION	FUNCTION %s does not exist. Check the
	// 'Function Name Parsing and Resolution' section in the Reference Manual
	public const ER_FUNC_INEXISTENT_NAME_COLLISION = 1630;

	// 4002		ER_WITH_COL_WRONG_LIST	WITH column list and SELECT field list have different column counts
	public const ER_WITH_COL_WRONG_LIST = 4002;

	// 4004		ER_DUP_QUERY_NAME	Duplicate query name %`-.64s in WITH clause
	public const ER_DUP_QUERY_NAME = 4004;

	// 4014	ER_BAD_COMBINATION_OF_WINDOW_FRAME_BOUND_SPECS Unacceptable combination of window frame bound specifications
	public const ER_BAD_COMBINATION_OF_WINDOW_FRAME_BOUND_SPECS = 4014;

	// 4078	ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION	Illegal parameter data types %s and %s for operation '%s'
	public const ER_ILLEGAL_PARAMETER_DATA_TYPES2_FOR_OPERATION = 4078;

	// 4079	ER_ILLEGAL_PARAMETER_DATA_TYPE_FOR_OPERATION	Illegal parameter data type %s for operation '%s'
	public const ER_ILLEGAL_PARAMETER_DATA_TYPE_FOR_OPERATION = 4079;

	// 4107		ER_INVALID_VALUE_TO_LIMIT	Limit only accepts integer values
	public const ER_INVALID_VALUE_TO_LIMIT = 4107;

	// 4161		ER_UNKNOWN_DATA_TYPE	Unknown data type: '%-.64s'
	public const ER_UNKNOWN_DATA_TYPE = 4161;

	// No data supplied for parameters in prepared statement
	public const MYSQLI_NO_DATA_FOR_PREPARED_PARAMS = 2031;
}
