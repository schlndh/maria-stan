<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Ast\Expr\FunctionCall\WindowFunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\Exception\ShouldNotHappenException;

use function count;

final class FirstValue implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['FIRST_VALUE'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::WINDOW;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		if (! $functionCall instanceof WindowFunctionCall) {
			throw new ShouldNotHappenException(
				'Expected WindowFunctionCall for FIRST_VALUE, got ' . $functionCall::class,
			);
		}

		$args = $functionCall->functionCall->getArguments();
		$argCount = count($args);

		if ($argCount !== 1) {
			throw new ParserException(
				FunctionInfoHelper::createArgumentCountErrorMessageFixed(
					$functionCall->getFunctionName(),
					1,
					$argCount,
				),
			);
		}
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
		if (! $functionCall instanceof WindowFunctionCall) {
			throw new ShouldNotHappenException(
				'Expected WindowFunctionCall for FIRST_VALUE, got ' . $functionCall::class,
			);
		}

		$arg = $argumentTypes[0];
		$isNullable = $arg->isNullable || FunctionInfoHelper::canWindowFrameBeEmpty($functionCall->frame);

		return new ExprTypeResult($arg->type, $isNullable);
	}
}
