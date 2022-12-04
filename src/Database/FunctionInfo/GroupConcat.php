<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Schema\DbType\VarcharType;

final class GroupConcat implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['GROUP_CONCAT'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::AGGREGATE;
	}

	/** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter */
	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		// GROUP_CONCAT has custom parsing implemented.
	}

	/**
	 * @inheritDoc
	 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
	 */
	public function getReturnType(FunctionCall $functionCall, array $argumentTypes): ExprTypeResult
	{
		return new ExprTypeResult(new VarcharType(), true);
	}
}
