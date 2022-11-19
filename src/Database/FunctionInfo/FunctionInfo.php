<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\QueryResultField;
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
	 * @param array<QueryResultField> $argumentTypes
	 * @throws AnalyserException
	 */
	public function getReturnType(
		FunctionCall $functionCall,
		array $argumentTypes,
		string $nodeContent,
	): QueryResultField;
}
