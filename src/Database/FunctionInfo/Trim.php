<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Schema\DbType\VarcharType;

final class Trim implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['TRIM'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	/** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter */
	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		// TRIM has custom parsing implemented.
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
		$isNullable = false;

		foreach ($argumentTypes as $argumentType) {
			$isNullable = $isNullable || $argumentType->isNullable;
		}

		return new QueryResultField($nodeContent, new VarcharType(), $isNullable);
	}
}
