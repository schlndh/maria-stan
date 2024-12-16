<?php

declare(strict_types=1);

namespace MariaStan\Util;

use mysqli;

use function assert;
use function is_string;
use function str_replace;

use const MYSQLI_AUTO_INCREMENT_FLAG;
use const MYSQLI_BINARY_FLAG;
use const MYSQLI_BLOB_FLAG;
use const MYSQLI_ENUM_FLAG;
use const MYSQLI_MULTIPLE_KEY_FLAG;
use const MYSQLI_NO_DEFAULT_VALUE_FLAG;
use const MYSQLI_NOT_NULL_FLAG;
use const MYSQLI_NUM_FLAG;
use const MYSQLI_ON_UPDATE_NOW_FLAG;
use const MYSQLI_PART_KEY_FLAG;
use const MYSQLI_PRI_KEY_FLAG;
use const MYSQLI_SET_FLAG;
use const MYSQLI_TIMESTAMP_FLAG;
use const MYSQLI_UNIQUE_KEY_FLAG;
use const MYSQLI_UNSIGNED_FLAG;
use const MYSQLI_ZEROFILL_FLAG;

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

	public static function getDatabaseName(mysqli $db): string
	{
		$dbName = $db->query('SELECT DATABASE()')->fetch_column();
		assert(is_string($dbName));

		return $dbName;
	}

	/** @return list<string> */
	public static function getFlagNames(int $value): array
	{
		$flags = [
			MYSQLI_NOT_NULL_FLAG => 'NOT_NULL',
			MYSQLI_PRI_KEY_FLAG => 'PRIMARY_KEY',
			MYSQLI_UNIQUE_KEY_FLAG => 'UNIQUE_KEY',
			MYSQLI_MULTIPLE_KEY_FLAG => 'MULTIPLE_KEY',
			MYSQLI_BLOB_FLAG => 'BLOB',
			MYSQLI_UNSIGNED_FLAG => 'UNSIGNED',
			MYSQLI_ZEROFILL_FLAG => 'ZEROFILL',
			MYSQLI_ENUM_FLAG => 'ENUM',
			MYSQLI_BINARY_FLAG => 'BINARY',
			MYSQLI_AUTO_INCREMENT_FLAG => 'AUTO_INCREMENT',
			MYSQLI_TIMESTAMP_FLAG => 'TIMESTAMP',
			MYSQLI_SET_FLAG => 'SET',
			MYSQLI_NO_DEFAULT_VALUE_FLAG => 'NO_DEFAULT_VALUE',
			MYSQLI_ON_UPDATE_NOW_FLAG => 'ON_UPDATE_NOW',
			MYSQLI_PART_KEY_FLAG => 'PART_KEY',
			MYSQLI_NUM_FLAG => 'NUM',
		];
		$result = [];

		foreach ($flags as $flag => $label) {
			if (($value & $flag) === $flag) {
				$result[] = $label;
			}
		}

		return $result;
	}
}
