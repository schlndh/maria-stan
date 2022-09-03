<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Expr\SpecialOpTypeEnum;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\TupleType;

use function assert;

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

	public static function createInvalidBinaryOpUsageErrorMessage(
		BinaryOpTypeEnum|SpecialOpTypeEnum $operator,
		DbTypeEnum $leftType,
		DbTypeEnum $rightType,
	): string {
		return "Operator {$operator->value} cannot be used between {$leftType->value} and {$rightType->value}";
	}

	public static function createInvalidTupleComparisonErrorMessage(DbType $left, DbType $right): string
	{
		$leftStr = self::formatDbType($left);
		$rightStr = self::formatDbType($right);

		return "Invalid comparison between {$leftStr} and {$rightStr}";
	}

	private static function formatDbType(DbType $type): string
	{
		if ($type::getTypeEnum() === DbTypeEnum::TUPLE) {
			assert($type instanceof TupleType);

			return "TUPLE<{$type->typeCount}>";
		}

		return $type::getTypeEnum()->value;
	}

	private static function formatColumnName(string $column, ?string $table): string
	{
		return $table === null
			? $column
			: "{$table}.{$column}";
	}
}
