<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;

use function count;

final class Abs implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['ABS'];
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
		// TRUTHY(ABS(A)) <=> TRUTHY(A)
		// FALSY(ABS(A)) <=> FALSY(A)
		// NOT_NULL(ABS(A)) <=> NOT_NULL(A)
		// NULL(ABS(A)) <=> NULL(A)
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
		bool $isNonEmptyAggResultSet,
	): ExprTypeResult {
		$number = $argumentTypes[0];
		$isNullable = $number->isNullable;
		$type = match ($number->type::getTypeEnum()) {
			DbTypeEnum::INT, DbTypeEnum::UNSIGNED_INT, DbTypeEnum::DECIMAL, DbTypeEnum::FLOAT, DbTypeEnum::NULL
				=> $number->type,
			DbTypeEnum::DATETIME => new DecimalType(),
			default => new FloatType(),
		};

		return new ExprTypeResult($type, $isNullable, null, $number->knowledgeBase);
	}
}
