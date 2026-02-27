<?php

declare(strict_types=1);

namespace MariaStan\Util;

use function is_int;
use function is_string;

abstract class TypeUtil
{
	/** @param array<mixed> $arr */
	public static function getStringValue(array $arr, int|string $key): string
	{
		$value = $arr[$key] ?? null;

		if (! is_string($value)) {
			throw new \TypeError("Value of key '{$key}' is not string.");
		}

		return $value;
	}

	/** @param array<mixed> $arr */
	public static function getIntValue(array $arr, int|string $key): int
	{
		$value = $arr[$key] ?? null;

		if (! is_int($value)) {
			throw new \TypeError("Value of key '{$key}' is not int.");
		}

		return $value;
	}
}
