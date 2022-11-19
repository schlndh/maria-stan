<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Ast\Expr\FunctionCall\StandardFunctionCall;
use MariaStan\Ast\Expr\FunctionCall\WindowFunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\NullType;

use function assert;
use function count;
use function reset;

final class Avg implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['AVG'];
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

		if (! $functionCall instanceof StandardFunctionCall) {
			throw new ParserException(
				"Parser issue: {$functionCall->getFunctionName()} should be parsed as standard function.",
			);
		}

		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount === 1) {
			return;
		}

		throw new ParserException("Function {$functionCall->getFunctionName()} takes 1 argument, got {$argCount}.");
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

		return new QueryResultField($nodeContent, $type, $arg->isNullable);
	}
}
