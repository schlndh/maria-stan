<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

enum AnalyserErrorTypeEnum: string
{
	case AMBIGUOUS_COLUMN = 'ambiguousColumn';
	case ASSIGN_TO_READONLY_COLUMN = 'assignToReadonlyColumn';
	case COLUMN_MISMATCH = 'columnMismatch';
	case DB_REFLECTION = 'dbReflection';
	case DUPLICATE_COLUMN = 'duplicateColumn';
	case INVALID_BINARY_OP = 'invalidBinaryOp';
	case INVALID_CTE = 'invalidCTE';
	case INVALID_FUNCTION_ARGUMENT = 'invalidFunctionArgument';
	case INVALID_LIKE_ESCAPE = 'invalidLikeEscape';
	case INVALID_LIKE_OP = 'invalidLikeOp';
	case INVALID_HAVING_COLUMN = 'invalidHavingColumn';
	case INVALID_TUPLE_USAGE = 'invalidTupleUsage';
	case NON_UNIQUE_TABLE_ALIAS = 'nonUniqueTableAlias';
	case MISSING_COLUMN_VALUE = 'missingColumnValue';
	case OTHER = 'other';
	case PARSE = 'parse';
	case UNKNOWN_TABLE = 'unknownTable';
	case UNKNOWN_COLUMN = 'unknownColumn';
	case UNSUPPORTED_FEATURE = 'unsupportedFeature';
}
