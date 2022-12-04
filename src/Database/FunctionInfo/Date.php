<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\NullType;

use function count;
use function in_array;

final class Date implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['DATE'];
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

	/**
	 * @inheritDoc
	 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
	 */
	public function getReturnType(FunctionCall $functionCall, array $argumentTypes): ExprTypeResult
	{
		$date = $argumentTypes[0];
		$isNullable = $date->isNullable || $date->type::getTypeEnum() !== DbTypeEnum::DATETIME;
		$type = in_array($date->type::getTypeEnum(), [DbTypeEnum::DATETIME, DbTypeEnum::VARCHAR], true)
			? new DateTimeType()
			: new NullType();

		return new ExprTypeResult($type, $isNullable);
	}
}
