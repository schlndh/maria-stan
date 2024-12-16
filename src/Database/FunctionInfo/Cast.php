<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\CastType\CastTypeEnum;
use MariaStan\Ast\Expr\CastType\IntegerCastType;
use MariaStan\Ast\Expr\FunctionCall\Cast as CastFunctionCall;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\MixedType;
use MariaStan\Schema\DbType\UnsignedIntType;
use MariaStan\Schema\DbType\VarcharType;

use function assert;
use function in_array;

final class Cast implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['CAST'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	/** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter */
	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		// CAST has custom parsing implemented.
	}

	/** @inheritDoc */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array
	{
		// TODO: implement this
		return [];
	}

	/** @inheritDoc */
	public function getReturnType(
		FunctionCall $functionCall,
		array $argumentTypes,
		?AnalyserConditionTypeEnum $condition,
		bool $isNonEmptyAggResultSet,
	): ExprTypeResult {
		assert($functionCall instanceof CastFunctionCall);
		$exprType = $argumentTypes[0];

		if ($exprType->type::getTypeEnum() === DbTypeEnum::NULL) {
			return new ExprTypeResult($exprType->type, true);
		}

		$isNullable = $exprType->isNullable;

		switch ($functionCall->castType::getCastType()) {
			case CastTypeEnum::BINARY:
			case CastTypeEnum::CHAR:
				$type = new VarcharType();
				break;
			case CastTypeEnum::DATE:
			case CastTypeEnum::DATETIME:
			case CastTypeEnum::TIME:
				$type = new DateTimeType();
				$isNullable = $isNullable || $exprType->type::getTypeEnum() !== DbTypeEnum::DATETIME;
				break;
			case CastTypeEnum::DECIMAL:
				$type = new DecimalType();
				break;
			case CastTypeEnum::DOUBLE:
			case CastTypeEnum::FLOAT:
				$type = new FloatType();
				break;
			case CastTypeEnum::INTEGER:
				$castType = $functionCall->castType;
				assert($castType instanceof IntegerCastType);
				$type = $castType->isSigned
					? new IntType()
					: new UnsignedIntType();
				break;
			case CastTypeEnum::DAY_SECOND:
				$type = new VarcharType();
				$isNullable = $isNullable
					|| in_array($exprType->type::getTypeEnum(), [DbTypeEnum::DATETIME, DbTypeEnum::VARCHAR], true);
				break;
			default:
				$type = new MixedType();
				$isNullable = true;
				break;
		}

		return new ExprTypeResult($type, $isNullable);
	}
}
