<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\Column;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;

use function assert;
use function count;
use function strtoupper;

final class Value implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['VALUE', 'VALUES'];
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

		$arg = $args[0];
		assert($arg instanceof Expr);

		if (! $arg instanceof Column) {
			throw new ParserException(
				"Function {$functionCall->getFunctionName()} expects column as argument,"
					. " got {$arg::getExprType()->value}",
			);
		}
	}

	/** @inheritDoc */
	public function getReturnType(
		FunctionCall $functionCall,
		array $argumentTypes,
		string $nodeContent,
	): QueryResultField {
		$col = $argumentTypes[0];
		// VALUE(...) can be used in SELECT as well, in which case it always returns null.
		$isNullable = $col->isNullable || strtoupper($functionCall->getFunctionName()) === 'VALUE';

		return new QueryResultField($nodeContent, $col->type, $isNullable);
	}
}