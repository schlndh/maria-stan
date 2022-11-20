<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

abstract class FunctionInfoHelper
{
	public static function createArgumentCountErrorMessageFixed(
		string $functionName,
		int $expectedParams,
		int $actualParams,
	): string {
		$argumentsStr = $expectedParams === 1
			? 'argument'
			: 'arguments';

		return "Function {$functionName} takes {$expectedParams} {$argumentsStr}, got {$actualParams}.";
	}

	public static function createArgumentCountErrorMessageRange(
		string $functionName,
		int $expectedParamsMin,
		int $expectedParamsMax,
		int $actualParams,
	): string {
		return "Function {$functionName} takes {$expectedParamsMin}-{$expectedParamsMax} arguments,"
			. " got {$actualParams}.";
	}
}
