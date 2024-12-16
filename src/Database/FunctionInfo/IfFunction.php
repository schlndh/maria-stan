<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;

use function count;

final class IfFunction implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['IF'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount !== 3) {
			throw new ParserException(
				FunctionInfoHelper::createArgumentCountErrorMessageFixed(
					$functionCall->getFunctionName(),
					3,
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
		$then = $argumentTypes[1];
		$else = $argumentTypes[2];
		$isNullable = $then->isNullable || $else->isNullable;
		$lt = $then->type::getTypeEnum();
		$rt = $else->type::getTypeEnum();
		$typesInvolved = [
			$lt->value => 1,
			$rt->value => 1,
		];

		// For some reason IF works differently from COALESCE/UNION/... in this one case.
		if (isset($typesInvolved[DbTypeEnum::NULL->value], $typesInvolved[DbTypeEnum::UNSIGNED_INT->value])) {
			$type = $lt === DbTypeEnum::NULL
				? $else->type
				: $then->type;
		} else {
			$type = FunctionInfoHelper::castToCommonType($then->type, $else->type);
		}

		return new ExprTypeResult($type, $isNullable);
	}
}
