<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\NullType;

use function array_fill;
use function count;

final class GreatestLeast implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['GREATEST', 'LEAST'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount >= 2) {
			return;
		}

		throw new ParserException(
			FunctionInfoHelper::createArgumentCountErrorMessageMin(
				$functionCall->getFunctionName(),
				2,
				$argCount,
			),
		);
	}

	/** @inheritDoc */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array
	{
		// TRUTHY(LEAST(A, ...)) => NOT_NULL(A, ...)
		// FALSY(LEAST(A, ...)) => NOT_NULL(A, ...)
		// NOT_NULL(LEAST(A, ...)) => NOT_NULL(A, ...)
		// NULL(LEAST(A, ...)) => NULL(A) || NULL(...)
		$innerCondition = match ($condition) {
			null, AnalyserConditionTypeEnum::NULL => $condition,
			// TODO: Change it to NOT_NULL after 10.11.8 https://jira.mariadb.org/browse/MDEV-21034
			AnalyserConditionTypeEnum::TRUTHY => null,
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
		$leftType = $argumentTypes[0]->type;
		$isNullable = $argumentTypes[0]->isNullable;
		$knowledgeBase = $argumentTypes[0]->knowledgeBase;
		$i = 1;
		$argCount = count($argumentTypes);

		while ($i < $argCount) {
			$rightKnowledgeBase = $argumentTypes[$i]->knowledgeBase;
			$rightType = $argumentTypes[$i]->type;
			$isNullable = $isNullable || $argumentTypes[$i]->isNullable;
			$i++;
			$leftType = self::castToCommonType($leftType, $rightType);
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

	private static function castToCommonType(DbType $leftType, DbType $rightType): DbType
	{
		$lt = $leftType::getTypeEnum();
		$rt = $rightType::getTypeEnum();
		$typesInvolved = [
			$lt->value => 1,
			$rt->value => 1,
		];

		if (isset($typesInvolved[DbTypeEnum::NULL->value])) {
			return new NullType();
		}

		if (isset($typesInvolved[DbTypeEnum::DATETIME->value])) {
			return new DateTimeType();
		}

		if (isset($typesInvolved[DbTypeEnum::VARCHAR->value]) && count($typesInvolved) === 2) {
			return new FloatType();
		}

		return FunctionInfoHelper::castToCommonType($leftType, $rightType);
	}
}
