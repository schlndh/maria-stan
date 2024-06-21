<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\FloatType;

use function count;

final class Round implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['ROUND'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount > 0 && $argCount <= 2) {
			return;
		}

		throw new ParserException(
			FunctionInfoHelper::createArgumentCountErrorMessageRange(
				$functionCall->getFunctionName(),
				1,
				2,
				$argCount,
			),
		);
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
		$valueType = $argumentTypes[0];
		$digitsType = $argumentTypes[1] ?? null;
		$isNullable = $valueType->isNullable || $digitsType?->isNullable;
		$type = match ($valueType->type::getTypeEnum()) {
			DbTypeEnum::VARCHAR => new FloatType(),
			DbTypeEnum::DECIMAL => $digitsType?->type::getTypeEnum() === DbTypeEnum::NULL
				? new FloatType()
				: $valueType->type,
			default => $valueType->type,
		};

		return new ExprTypeResult($type, $isNullable);
	}
}
