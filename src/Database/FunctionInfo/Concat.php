<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\VarcharType;

use function array_fill;
use function count;

final class Concat implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['CONCAT'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

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

	/** @inheritDoc */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array
	{
		if (count($arguments) === 1) {
			return [$condition];
		}

		// TRUTHY(CONCAT(A, B)) => NOT_NULL(A) && NOT_NULL(B)
		// - same for FALSY
		// NULL(CONCAT(A, B)) <=> NULL(A) || NULL(B)
		// NOT_NULL(CONCAT(A, B)) <=> NOT_NULL(A) && NOT_NULL(B)
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
		bool $isNonEmptyAggResultSet,
	): ExprTypeResult {
		$leftType = $this->concatOneType($argumentTypes[0]->type);
		$isNullable = $argumentTypes[0]->isNullable;
		$knowledgeBase = $argumentTypes[0]->knowledgeBase;
		$i = 1;
		$argCount = count($argumentTypes);

		while ($i < $argCount) {
			$rightKnowledgeBase = $argumentTypes[$i]->knowledgeBase;
			$rightType = $this->concatOneType($argumentTypes[$i]->type);
			$isNullable = $isNullable || $argumentTypes[$i]->isNullable;
			$i++;
			$leftType = $this->concatTwoTypes($leftType, $rightType);
			$knowledgeBase = $rightKnowledgeBase === null
				? null
				: match ($condition) {
					null => null,
					AnalyserConditionTypeEnum::NULL => $knowledgeBase?->or($rightKnowledgeBase),
					default => $knowledgeBase?->and($rightKnowledgeBase),
				};
		}

		return new ExprTypeResult($leftType, $isNullable, null, $knowledgeBase);
	}

	private function concatOneType(DbType $type): DbType
	{
		return match ($type::getTypeEnum()) {
			DbTypeEnum::NULL, DbTypeEnum::VARCHAR => $type,
			default => new VarcharType(),
		};
	}

	private function concatTwoTypes(DbType $left, DbType $right): DbType
	{
		if ($left::getTypeEnum() === DbTypeEnum::NULL) {
			return $left;
		}

		if ($right::getTypeEnum() === DbTypeEnum::NULL) {
			return $right;
		}

		return $left;
	}
}
