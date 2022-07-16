<?php

declare(strict_types=1);

namespace MariaStan\Util;

/** @see https://mariadb.com/kb/en/mariadb-error-codes/ */
class MariaDbErrorCodes
{
	// 1054	42S22	ER_BAD_FIELD_ERROR	Unknown column '%s' in '%s'
	public const ER_BAD_FIELD_ERROR = 1054;
}
