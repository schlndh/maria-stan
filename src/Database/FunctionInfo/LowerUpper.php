<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\VarcharType;

use function count;

final class LowerUpper implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['LOWER', 'LCASE', 'UPPER', 'UCASE'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
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

	/** @inheritDoc */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array
	{
		return [$condition];
	}

	/**
	 * @inheritDoc
	 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
	 */
	public function getReturnType(
		FunctionCall $functionCall,
		array $argumentTypes,
		?AnalyserConditionTypeEnum $condition,
	): ExprTypeResult {
		$arg = $argumentTypes[0];

		return new ExprTypeResult(
			new VarcharType(),
			$arg->isNullable,
			null,
			$arg->knowledgeBase,
		);
	}
}
