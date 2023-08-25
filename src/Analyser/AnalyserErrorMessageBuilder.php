<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Ast\Expr\BinaryOpTypeEnum;
use MariaStan\Ast\Expr\SpecialOpTypeEnum;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\TupleType;

use function assert;
use function max;
use function min;

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

	public static function createAmbiguousColumnErrorMessage(
		string $column,
		?string $table = null,
		bool $isWarning = false,
	): string {
		$column = self::formatColumnName($column, $table);

		return ($isWarning ? 'Warning: ' : '') . "Ambiguous column '{$column}'";
	}

	public static function createDuplicateColumnName(string $column): string
	{
		return "Duplicate column name '{$column}'";
	}

	public static function createInvalidTupleUsageErrorMessage(TupleType $tupleType): string
	{
		return "Expected single value, got " . self::formatDbType($tupleType);
	}

	public static function createInvalidBinaryOpUsageErrorMessage(
		BinaryOpTypeEnum|SpecialOpTypeEnum $operator,
		DbTypeEnum $leftType,
		DbTypeEnum $rightType,
	): string {
		return "Operator {$operator->value} cannot be used between {$leftType->value} and {$rightType->value}";
	}

	public static function createInvalidLikeUsageErrorMessage(
		DbTypeEnum $expressionType,
		DbTypeEnum $patternType,
		?DbTypeEnum $escapeCharType = null,
	): string {
		$suffix = $escapeCharType !== null
			? " ESCAPE {$escapeCharType->value}"
			: '';

		return "Operator LIKE cannot be used as: {$expressionType->value} LIKE {$patternType->value}{$suffix}";
	}

	public static function createInvalidLikeEscapeMulticharErrorMessage(string $escape): string
	{
		return "ESCAPE can only be single character. Got '{$escape}'.";
	}

	public static function createDifferentNumberOfColumnsErrorMessage(int $left, int $right): string
	{
		return "The used SELECT statements have a different number of columns: {$left} vs {$right}.";
	}

	public static function createDifferentNumberOfWithColumnsErrorMessage(int $columnList, int $query): string
	{
		return "Column list of WITH and the subquery have to have the same number of columns."
			. " Got {$columnList} vs {$query}.";
	}

	public static function createInvalidFunctionArgumentErrorMessage(
		string $functionName,
		int $position,
		DbType $argumentType,
	): string {
		$typeStr = self::formatDbType($argumentType);

		return "Function {$functionName} does not accept {$typeStr} as argument {$position}.";
	}

	/** @param non-empty-array<int> $possibleCounts */
	public static function createMismatchedFunctionArgumentsErrorMessage(
		string $functionName,
		int $argumentCount,
		array $possibleCounts,
	): string {
		$min = min($possibleCounts);
		$max = max($possibleCounts);
		$acceptedArgs = $min === $max
			? (string) $min
			: "{$min} - {$max}";

		return "Function {$functionName} requires {$acceptedArgs} arguments, {$argumentCount} given.";
	}

	public static function createInvalidTupleComparisonErrorMessage(DbType $left, DbType $right): string
	{
		$leftStr = self::formatDbType($left);
		$rightStr = self::formatDbType($right);

		return "Invalid comparison between {$leftStr} and {$rightStr}";
	}

	public static function createMismatchedInsertColumnCountErrorMessage(int $expected, int $got): string
	{
		return "Insert expected {$expected} columns, but got {$got} columns.";
	}

	public static function createMissingValueForColumnErrorMessage(string $column): string
	{
		return "Column {$column} has no default value and none was provided.";
	}

	public static function createInvalidHavingColumn(string $column): string
	{
		return "Column {$column} cannot be used in HAVING clause. Only columns that are part of the GROUP BY clause,"
			. " columns used in aggregate functions, columns from the SELECT list and outer subqueries can be used.";
	}

	public static function createTvcDifferentNumberOfValues(int $min, int $max): string
	{
		return "The used table value constructor has a different number of values: {$min} - {$max}.";
	}

	public static function createAssignToNonWritableColumn(string $column, ?string $table = null): string
	{
		return 'You cannot assign to \'' . self::formatColumnName($column, $table) . '\'.';
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
