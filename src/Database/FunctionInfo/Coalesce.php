<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;

use function array_fill;
use function count;
use function strtoupper;

final class Coalesce implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['COALESCE', 'IFNULL', 'NVL'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

		if (strtoupper($functionCall->getFunctionName()) === 'COALESCE') {
			if ($argCount > 0) {
				return;
			}

			throw new ParserException(
				FunctionInfoHelper::createArgumentCountErrorMessageMin(
					$functionCall->getFunctionName(),
					1,
					$argCount,
				),
			);
		}

		if ($argCount !== 2) {
			throw new ParserException(
				FunctionInfoHelper::createArgumentCountErrorMessageFixed(
					$functionCall->getFunctionName(),
					2,
					$argCount,
				),
			);
		}
	}

	/** @inheritDoc */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array
	{
		if (count($arguments) === 1) {
			return [$condition];
		}

		// TRUTHY(COALESCE(A, B)) <=> TRUTHY(A) || (NULL(A) && TRUTHY(B))
		//	- simplify to NOT_NULL(A) || NOT_NULL(B) which is implied by the above.
		// - similarly for FALSY
		// NULL(COALESCE(A, B)) <=> NULL(A) && NULL(B)
		// NOT_NULL(COALESCE(A, B)) <=> NOT_NULL(A) || NOT_NULL(B)
		$innerCondition = match ($condition) {
			null, AnalyserConditionTypeEnum::NULL => $condition,
			default => AnalyserConditionTypeEnum::NOT_NULL,
		};

		return array_fill(0, count($arguments), $innerCondition);
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
		$leftType = $argumentTypes[0]->type;
		$isNullable = $argumentTypes[0]->isNullable;
		$knowledgeBase = $argumentTypes[0]->knowledgeBase;
		$i = 1;
		$argCount = count($argumentTypes);

		while ($i < $argCount) {
			$rightKnowledgeBase = $argumentTypes[$i]->knowledgeBase;
			$rightType = $argumentTypes[$i]->type;
			$isNullable = $isNullable && $argumentTypes[$i]->isNullable;
			$i++;
			$leftType = FunctionInfoHelper::castToCommonType($leftType, $rightType);
			$knowledgeBase = $rightKnowledgeBase === null
				? null
				: match ($condition) {
					null => null,
					AnalyserConditionTypeEnum::NULL => $knowledgeBase?->and($rightKnowledgeBase),
					default => $knowledgeBase?->or($rightKnowledgeBase),
				};
		}

		return new ExprTypeResult($leftType, $isNullable, null, $knowledgeBase);
	}
}
