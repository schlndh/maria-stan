<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Expr\SpecialOpTypeEnum;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\TupleType;

use function assert;

class AnalyserErrorBuilder
{
	public static function createUnknownColumnError(string $column, ?string $table = null): AnalyserError
	{
		$column = self::formatColumnName($column, $table);

		return new AnalyserError("Unknown column '{$column}'", AnalyserErrorTypeEnum::UNKNOWN_COLUMN);
	}

	public static function createNotUniqueTableAliasErrorMessage(string $table): string
	{
		return "Not unique table/alias: '{$table}'";
	}

	public static function createNotUniqueTableAliasError(string $table): AnalyserError
	{
		return new AnalyserError(
			self::createNotUniqueTableAliasErrorMessage($table),
			AnalyserErrorTypeEnum::NON_UNIQUE_TABLE_ALIAS,
		);
	}

	public static function createTableDoesntExistErrorMessage(string $table): string
	{
		return "Table '{$table}' doesn't exist";
	}

	public static function createTableDoesntExistError(string $table): AnalyserError
	{
		return new AnalyserError(
			self::createTableDoesntExistErrorMessage($table),
			AnalyserErrorTypeEnum::UNKNOWN_TABLE,
		);
	}

	public static function createAmbiguousColumnError(
		string $column,
		?string $table = null,
		bool $isWarning = false,
	): AnalyserError {
		$column = self::formatColumnName($column, $table);

		return new AnalyserError(
			($isWarning
				? 'Warning: '
				: '') . "Ambiguous column '{$column}'",
			AnalyserErrorTypeEnum::AMBIGUOUS_COLUMN,
		);
	}

	public static function createInvalidTupleUsageError(TupleType $tupleType): AnalyserError
	{
		return new AnalyserError(
			"Expected single value, got " . self::formatDbType($tupleType),
			AnalyserErrorTypeEnum::INVALID_TUPLE_USAGE,
		);
	}

	public static function createInvalidBinaryOpUsageError(
		BinaryOpTypeEnum|SpecialOpTypeEnum $operator,
		DbTypeEnum $leftType,
		DbTypeEnum $rightType,
	): AnalyserError {
		return new AnalyserError(
			"Operator {$operator->value} cannot be used between {$leftType->value} and {$rightType->value}",
			AnalyserErrorTypeEnum::INVALID_BINARY_OP,
		);
	}

	public static function createInvalidLikeUsageError(
		DbTypeEnum $expressionType,
		DbTypeEnum $patternType,
		?DbTypeEnum $escapeCharType = null,
	): AnalyserError {
		$suffix = $escapeCharType !== null
			? " ESCAPE {$escapeCharType->value}"
			: '';

		return new AnalyserError(
			"Operator LIKE cannot be used as: {$expressionType->value} LIKE {$patternType->value}{$suffix}",
			AnalyserErrorTypeEnum::INVALID_LIKE_OP,
		);
	}

	public static function createInvalidLikeEscapeMulticharError(string $escape): AnalyserError
	{
		return new AnalyserError(
			"ESCAPE can only be single character. Got '{$escape}'.",
			AnalyserErrorTypeEnum::INVALID_LIKE_ESCAPE,
		);
	}

	public static function createDifferentNumberOfColumnsError(int $left, int $right): AnalyserError
	{
		return new AnalyserError(
			"The used SELECT statements have a different number of columns: {$left} vs {$right}.",
			AnalyserErrorTypeEnum::COLUMN_MISMATCH,
		);
	}

	public static function createDifferentNumberOfWithColumnsError(int $columnList, int $query): AnalyserError
	{
		return new AnalyserError(
			"Column list of WITH and the subquery have to have the same number of columns."
			. " Got {$columnList} vs {$query}.",
			AnalyserErrorTypeEnum::COLUMN_MISMATCH,
		);
	}

	public static function createInvalidFunctionArgumentError(
		string $functionName,
		int $position,
		DbType $argumentType,
	): AnalyserError {
		$typeStr = self::formatDbType($argumentType);

		return new AnalyserError(
			"Function {$functionName} does not accept {$typeStr} as argument {$position}.",
			AnalyserErrorTypeEnum::INVALID_FUNCTION_ARGUMENT,
		);
	}

	public static function createInvalidTupleComparisonError(DbType $left, DbType $right): AnalyserError
	{
		$leftStr = self::formatDbType($left);
		$rightStr = self::formatDbType($right);

		return new AnalyserError(
			"Invalid comparison between {$leftStr} and {$rightStr}",
			AnalyserErrorTypeEnum::INVALID_TUPLE_USAGE,
		);
	}

	public static function createMismatchedInsertColumnCountError(int $expected, int $got): AnalyserError
	{
		return new AnalyserError(
			"Insert expected {$expected} columns, but got {$got} columns.",
			AnalyserErrorTypeEnum::COLUMN_MISMATCH,
		);
	}

	public static function createMissingValueForColumnError(string $column): AnalyserError
	{
		return new AnalyserError(
			"Column {$column} has no default value and none was provided.",
			AnalyserErrorTypeEnum::MISSING_COLUMN_VALUE,
		);
	}

	public static function createInvalidHavingColumnError(string $column): AnalyserError
	{
		return new AnalyserError(
			"Column {$column} cannot be used in HAVING clause. Only columns that are part of the GROUP BY clause,"
			. " columns used in aggregate functions, columns from the SELECT list and outer subqueries can be used.",
			AnalyserErrorTypeEnum::INVALID_HAVING_COLUMN,
		);
	}

	public static function createTvcDifferentNumberOfValues(int $min, int $max): AnalyserError
	{
		return new AnalyserError(
			"The used table value constructor has a different number of values: {$min} - {$max}.",
			AnalyserErrorTypeEnum::COLUMN_MISMATCH,
		);
	}

	public static function createAssignToReadonlyColumnError(string $column, ?string $table = null): AnalyserError
	{
		return new AnalyserError(
			'You cannot assign to \'' . self::formatColumnName($column, $table) . '\'.',
			AnalyserErrorTypeEnum::ASSIGN_TO_READONLY_COLUMN,
		);
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
