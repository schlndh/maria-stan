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

	// 1109	42S02	ER_UNKNOWN_TABLE	Unknown table '%s' in %s
	public const ER_UNKNOWN_TABLE = 1109;

	// 1136	21S01	ER_WRONG_VALUE_COUNT_ON_ROW	Column count doesn't match value count at row %ld
	public const ER_WRONG_VALUE_COUNT_ON_ROW = 1136;

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

	// 1264	22003	ER_WARN_DATA_OUT_OF_RANGE	Out of range value for column '%s' at row %ld
	public const ER_WARN_DATA_OUT_OF_RANGE = 1264;

	// 1273	HY000	ER_UNKNOWN_COLLATION	Unknown collation: '%s'
	public const ER_UNKNOWN_COLLATION = 1273;

	// 1288	HY000	ER_NON_UPDATABLE_TABLE	The target table %s of the %s is not updatable
	public const ER_NON_UPDATABLE_TABLE = 1288;

	// 1292	22007	ER_TRUNCATED_WRONG_VALUE	Truncated incorrect %s value: '%s'
	public const ER_TRUNCATED_WRONG_VALUE = 1292;

	// 1364	HY000	ER_NO_DEFAULT_FOR_FIELD	Field '%s' doesn't have a default value
	public const ER_NO_DEFAULT_FOR_FIELD = 1364;

	// 1365	22012	ER_DIVISION_BY_ZER	Division by 0
	public const ER_DIVISION_BY_ZER = 1365;

	// 1582	42000	ER_WRONG_PARAMCOUNT_TO_NATIVE_FCT	Incorrect parameter count in the call to native function '%s'
	public const ER_WRONG_PARAMCOUNT_TO_NATIVE_FCT = 1582;

	// 1630	42000 ER_FUNC_INEXISTENT_NAME_COLLISION	FUNCTION %s does not exist. Check the
	// 'Function Name Parsing and Resolution' section in the Reference Manual
	public const ER_FUNC_INEXISTENT_NAME_COLLISION = 1630;

	// 1649	HY000	ER_UNKNOWN_LOCALE	Unknown locale: '%s'
	public const ER_UNKNOWN_LOCALE = 1649;

	// 1918		ER_BAD_DATA 22007	Encountered illegal value '%-.128s' when converting to %-.32s
	public const ER_BAD_DATA = 1918;

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

	// 4099		ER_WRONG_NUMBER_OF_VALUES_IN_TVC	The used table value constructor has a different number of values
	public const ER_WRONG_NUMBER_OF_VALUES_IN_TVC = 4099;

	// 4100		ER_FIELD_REFERENCE_IN_TVC	Field reference '%-.192s' can't be used in table value constructor
	public const ER_FIELD_REFERENCE_IN_TVC = 4100;

	// 4107		ER_INVALID_VALUE_TO_LIMIT	Limit only accepts integer values
	public const ER_INVALID_VALUE_TO_LIMIT = 4107;

	// 4161		ER_UNKNOWN_DATA_TYPE	Unknown data type: '%-.64s'
	public const ER_UNKNOWN_DATA_TYPE = 4161;

	// No data supplied for parameters in prepared statement
	public const MYSQLI_NO_DATA_FOR_PREPARED_PARAMS = 2031;
}
