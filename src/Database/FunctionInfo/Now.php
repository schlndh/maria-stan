<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Ast\Expr\LiteralInt;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DateTimeType;

use function assert;
use function count;
use function reset;

final class Now implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return [
			'NOW',
			'CURRENT_TIMESTAMP',
			'LOCALTIME',
			'LOCALTIMESTAMP',
		];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount > 1) {
			throw new ParserException(
				FunctionInfoHelper::createArgumentCountErrorMessageRange(
					$functionCall->getFunctionName(),
					0,
					1,
					$argCount,
				),
			);
		}

		if ($argCount === 0) {
			return;
		}

		$precision = reset($args);
		assert($precision instanceof Expr);

		if ($precision instanceof LiteralInt) {
			return;
		}

		$type = $precision::getExprType();

		throw new ParserException(
			"Precision argument to {$functionCall->getFunctionName()} has to be literal int, got {$type->value}.",
		);
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
		return new QueryResultField($nodeContent, new DateTimeType(), false);
	}
}
