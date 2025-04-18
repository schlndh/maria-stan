<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Ast\WindowFrame;
use MariaStan\Ast\WindowFrameBoundTypeEnum;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\MixedType;
use MariaStan\Schema\DbType\VarcharType;

use function in_array;

abstract class FunctionInfoHelper
{
	public static function createArgumentCountErrorMessageFixed(
		string $functionName,
		int $expectedParams,
		int $actualParams,
	): string {
		$argumentsStr = $expectedParams === 1
			? 'argument'
			: 'arguments';

		return "Function {$functionName} takes {$expectedParams} {$argumentsStr}, got {$actualParams}.";
	}

	public static function createArgumentCountErrorMessageRange(
		string $functionName,
		int $expectedParamsMin,
		int $expectedParamsMax,
		int $actualParams,
	): string {
		return "Function {$functionName} takes {$expectedParamsMin}-{$expectedParamsMax} arguments,"
			. " got {$actualParams}.";
	}

	public static function createArgumentCountErrorMessageMin(
		string $functionName,
		int $expectedParamsMin,
		int $actualParams,
	): string {
		$argumentsStr = $expectedParamsMin === 1
			? 'argument'
			: 'arguments';

		return "Function {$functionName} takes at least {$expectedParamsMin} {$argumentsStr},"
			. " got {$actualParams}.";
	}

	public static function castToCommonType(DbType $leftType, DbType $rightType): DbType
	{
		$lt = $leftType::getTypeEnum();
		$rt = $rightType::getTypeEnum();
		$typesInvolved = [
			$lt->value => 1,
			$rt->value => 1,
		];

		if (
			$leftType::getTypeEnum() === $rightType::getTypeEnum()
			// We'd have to check that the cases are the same.
			&& $leftType::getTypeEnum() !== DbTypeEnum::ENUM
		) {
			return $leftType;
		}

		if (isset($typesInvolved[DbTypeEnum::NULL->value])) {
			$leftType = $lt === DbTypeEnum::NULL
				? $rightType
				: $leftType;

			if ($leftType::getTypeEnum() === DbTypeEnum::UNSIGNED_INT) {
				$leftType = new DecimalType();
			}
		} elseif (isset($typesInvolved[DbTypeEnum::MIXED->value])) {
			$leftType = new MixedType();
		} elseif (isset($typesInvolved[DbTypeEnum::VARCHAR->value])) {
			$leftType = new VarcharType();
		} elseif (isset($typesInvolved[DbTypeEnum::DATETIME->value])) {
			$leftType = new VarcharType();
		} elseif (isset($typesInvolved[DbTypeEnum::FLOAT->value])) {
			$leftType = new FloatType();
		} elseif (
			isset($typesInvolved[DbTypeEnum::DECIMAL->value])
			|| isset($typesInvolved[DbTypeEnum::UNSIGNED_INT->value])
		) {
			$leftType = new DecimalType();
		} elseif (isset($typesInvolved[DbTypeEnum::INT->value])) {
			$leftType = new IntType();
		} else {
			$leftType = new MixedType();
		}

		return $leftType;
	}

	public static function canWindowFrameBeEmpty(?WindowFrame $windowFrame): bool
	{
		return ! (
			$windowFrame?->to === null
			// X FOLLOWING and CURRENT ROW is rejected by MariaDB
			|| in_array(
				WindowFrameBoundTypeEnum::CURRENT_ROW,
				[$windowFrame->from->type, $windowFrame->to->type],
				true,
			)
			|| ($windowFrame->from->type->isPreceding() && $windowFrame->to->type->isFollowing())
		);
	}
}
