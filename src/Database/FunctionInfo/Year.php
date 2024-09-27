<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\NullType;

use function count;
use function in_array;

final class Year implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['YEAR'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
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
		// TRUTHY(YEAR(A)) => NOT_NULL(A)
		// FALSY(YEAR(A)) => NOT_NULL(A)
		// NOT_NULL(YEAR(A)) => NOT_NULL(A)
		// NULL(YEAR(A)) => []
		$innerCondition = match ($condition) {
			null, AnalyserConditionTypeEnum::NULL => null,
			default => AnalyserConditionTypeEnum::NOT_NULL,
		};

		return [$innerCondition];
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
		$isNullable = $date->isNullable || $date->type::getTypeEnum() !== DbTypeEnum::DATETIME;
		$type = in_array($date->type::getTypeEnum(), [DbTypeEnum::DATETIME, DbTypeEnum::VARCHAR], true)
			? new IntType()
			: new NullType();

		return new ExprTypeResult($type, $isNullable, null, $date->knowledgeBase);
	}
}
