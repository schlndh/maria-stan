<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Schema\DbType\IntType;

final class Count implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['COUNT'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::AGGREGATE_OR_WINDOW;
	}

	/** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter */
	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		// Count has custom parsing implemented.
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
		return new QueryResultField($nodeContent, new IntType(), false);
	}
}
