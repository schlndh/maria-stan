<?php

declare(strict_types=1);

namespace MariaStan\Util;

use mysqli;

use function str_replace;

abstract class MysqliUtil
{
	public static function quoteIdentifier(string $identifier): string
	{
		$q = '`';

		return $q . str_replace("$q", "$q$q", $identifier) . $q;
	}

	/** @param scalar|null $value */
	public static function quoteValue(mysqli $db, mixed $value): string
	{
		if ($value === null) {
			return 'NULL';
		}

		return '"' . $db->real_escape_string((string) $value) . '"';
	}
}
