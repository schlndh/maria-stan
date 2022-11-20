<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Ast\Expr\FunctionCall\WindowFunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\NullType;

use function assert;
use function count;
use function reset;

final class Sum implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['SUM'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::AGGREGATE_OR_WINDOW;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		if ($functionCall instanceof WindowFunctionCall) {
			$functionCall = $functionCall->functionCall;
		}

		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount === 1) {
			return;
		}

		throw new ParserException(
			FunctionInfoHelper::createArgumentCountErrorMessageFixed(
				$functionCall->getFunctionName(),
				1,
				$argCount,
			),
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
		$arg = reset($argumentTypes);
		assert($arg instanceof QueryResultField);

		$type = match ($arg->type::getTypeEnum()) {
			DbTypeEnum::NULL => new NullType(),
			DbTypeEnum::FLOAT, DbTypeEnum::VARCHAR => new FloatType(),
			default => new DecimalType(),
		};

		return new QueryResultField($nodeContent, $type, true);
	}
}