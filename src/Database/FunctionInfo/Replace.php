<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\AnalyserKnowledgeBase;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\VarcharType;

use function array_reduce;
use function count;

final class Replace implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['REPLACE'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$argCount = count($functionCall->getArguments());

		if ($argCount === 3) {
			return;
		}

		throw new ParserException(
			FunctionInfoHelper::createArgumentCountErrorMessageFixed(
				$functionCall->getFunctionName(),
				3,
				$argCount,
			),
		);
	}

	/** @inheritDoc */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array
	{
		// TRUTHY(REPLACE(A, B, C)) => NOT_NULL(REPLACE(A, B, C))
		// FALSY(REPLACE(A, B, C)) => NOT_NULL(REPLACE(A, B, C))
		// NOT_NULL(REPLACE(A, B, C)) => NOT_NULL(A) && NOT_NULL(B) && NOT_NULL(C)
		//	- Mystery: When I run SELECT REPLACE('a', 'b', NULL); through mysql CLI client it returns 'a', not NULL.
		// NULL(REPLACE(A, B, C)) => NULL(A) || NULL(B) || NULL(C)

		return match ($condition) {
			null => [],
			AnalyserConditionTypeEnum::NULL => [$condition, $condition, $condition],
			default => [
				AnalyserConditionTypeEnum::NOT_NULL,
				AnalyserConditionTypeEnum::NOT_NULL,
				AnalyserConditionTypeEnum::NOT_NULL,
			],
		};
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
		$isNullable = false;
		$resultType = null;

		foreach ($argumentTypes as $argumentType) {
			$isNullable = $isNullable || $argumentType->isNullable;

			if ($argumentType->type::getTypeEnum() === DbTypeEnum::NULL) {
				$resultType = $argumentType->type;
			}
		}

		$knowledgeBase = array_reduce(
			$argumentTypes,
			match ($condition) {
				null => static fn () => null,
				AnalyserConditionTypeEnum::NULL
					=> static function (?AnalyserKnowledgeBase $carry, ExprTypeResult $item): ?AnalyserKnowledgeBase {
						if ($carry === null || $item->knowledgeBase === null) {
							return null;
						}

						return $carry->or($item->knowledgeBase);
					},
				default
					=> static function (?AnalyserKnowledgeBase $carry, ExprTypeResult $item): ?AnalyserKnowledgeBase {
						if ($carry === null) {
							return $item->knowledgeBase;
						}

						if ($item->knowledgeBase === null) {
							return $carry;
						}

						return $carry->and($item->knowledgeBase);
					},
			},
			match ($condition) {
				null => null,
				AnalyserConditionTypeEnum::NULL => AnalyserKnowledgeBase::createFixed(false),
				default => AnalyserKnowledgeBase::createFixed(true),
			},
		);

		return new ExprTypeResult($resultType ?? new VarcharType(), $isNullable, null, $knowledgeBase);
	}
}
