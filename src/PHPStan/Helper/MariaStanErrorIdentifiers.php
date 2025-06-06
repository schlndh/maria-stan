<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

abstract class MariaStanErrorIdentifiers
{
	public const DYNAMIC_SQL = 'dynamicSql';
	public const PLACEHOLDER_MISMATCH = 'placeholderMismatch';
	public const UNSUPPORTED_PLACEHOLDER = 'unsupportedPlaceholder';
	public const DB_REFLECTION_ERROR = 'dbReflection';
}
