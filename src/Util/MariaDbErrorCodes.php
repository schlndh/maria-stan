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
}
