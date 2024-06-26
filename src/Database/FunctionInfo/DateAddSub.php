<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\DbTypeEnum;

final class DateAddSub implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['DATE_ADD', 'DATE_SUB'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	/** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter */
	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		// DATE_ADD/DATE_SUB have custom parsing implemented.
	}

	/** @inheritDoc */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array
	{
		// TODO: implement this
		return [];
	}

	/**
	 * @inheritDoc
	 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
	 */
	public function getReturnType(
		FunctionCall $functionCall,
		array $argumentTypes,
		?AnalyserConditionTypeEnum $condition,
		bool $isNonEmptyAggResultSet,
	): ExprTypeResult {
		$date = $argumentTypes[0];
		$interval = $argumentTypes[1];
		$isNullable = $date->isNullable || $interval->isNullable || $date->type::getTypeEnum() !== DbTypeEnum::DATETIME;

		return new ExprTypeResult(new DateTimeType(), $isNullable);
	}
}
