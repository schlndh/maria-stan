<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Ast\Expr\FunctionCall\WindowFunctionCall;
use MariaStan\Parser\Exception\ParserException;

use function assert;
use function count;
use function reset;

final class MaxMin implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['MAX', 'MIN'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::AGGREGATE_OR_WINDOW;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		if ($functionCall instanceof WindowFunctionCall) {
			$functionCall = $functionCall->functionCall;
		}

		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount === 1) {
			return;
		}

		throw new ParserException(
			FunctionInfoHelper::createArgumentCountErrorMessageFixed(
				$functionCall->getFunctionName(),
				1,
				$argCount,
			),
		);
	}

	/**
	 * @inheritDoc
	 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
	 */
	public function getReturnType(FunctionCall $functionCall, array $argumentTypes): ExprTypeResult
	{
		$arg = reset($argumentTypes);
		assert($arg instanceof ExprTypeResult);

		return new ExprTypeResult($arg->type, true);
	}
}
