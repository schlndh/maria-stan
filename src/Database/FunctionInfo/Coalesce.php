<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;

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

	/**
	 * @inheritDoc
	 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
	 */
	public function getReturnType(
		FunctionCall $functionCall,
		array $argumentTypes,
		string $nodeContent,
	): QueryResultField {
		$leftType = $argumentTypes[0]->type;
		$isNullable = $argumentTypes[0]->isNullable;
		$i = 1;
		$argCount = count($argumentTypes);

		while ($i < $argCount) {
			$rightType = $argumentTypes[$i]->type;
			$isNullable = $isNullable && $argumentTypes[$i]->isNullable;
			$i++;
			$leftType = FunctionInfoHelper::castToCommonType($leftType, $rightType);
		}

		return new QueryResultField($nodeContent, $leftType, $isNullable);
	}
}
