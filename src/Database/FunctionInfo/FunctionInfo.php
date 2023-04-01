<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\AnalyserConditionTypeEnum;
use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\ExprTypeResult;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;

interface FunctionInfo
{
	/** @return array<string> */
	public function getSupportedFunctionNames(): array;

	public function getFunctionType(): FunctionTypeEnum;

	/** @throws ParserException */
	public function checkSyntaxErrors(FunctionCall $functionCall): void;

	/**
	 * @param array<Expr> $arguments
	 * @return array<?AnalyserConditionTypeEnum>
	 */
	public function getInnerConditions(?AnalyserConditionTypeEnum $condition, array $arguments): array;

	/**
	 * @param array<ExprTypeResult> $argumentTypes
	 * @throws AnalyserException
	 */
	public function getReturnType(
		FunctionCall $functionCall,
		array $argumentTypes,
		?AnalyserConditionTypeEnum $condition,
	): ExprTypeResult;
}
