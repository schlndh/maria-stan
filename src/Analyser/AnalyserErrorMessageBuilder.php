<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

class AnalyserErrorMessageBuilder
{
	public static function createUnknownColumnErrorMessage(string $column, ?string $table = null): string
	{
		$column = self::formatColumnName($column, $table);

		return "Unknown column '{$column}'";
	}

	public static function createNotUniqueTableAliasErrorMessage(string $table): string
	{
		return "Not unique table/alias: '{$table}'";
	}

	public static function createTableDoesntExistErrorMessage(string $table): string
	{
		return "Table '{$table}' doesn't exist";
	}

	public static function createAmbiguousColumnErrorMessage(string $column, ?string $table = null): string
	{
		$column = self::formatColumnName($column, $table);

		return "Ambiguous column '{$column}'";
	}

	public static function createDuplicateColumnName(string $column): string
	{
		return "Duplicate column name '{$column}'";
	}

	private static function formatColumnName(string $column, ?string $table): string
	{
		return $table === null
			? $column
			: "{$table}.{$column}";
	}
}
