<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\NullType;
use MariaStan\Schema\DbType\VarcharType;

use function count;
use function in_array;

final class DateFormat implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['DATE_FORMAT'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount < 2 || $argCount > 3) {
			throw new ParserException(
				FunctionInfoHelper::createArgumentCountErrorMessageRange(
					$functionCall->getFunctionName(),
					2,
					3,
					$argCount,
				),
			);
		}
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
		$date = $argumentTypes[0];
		$format = $argumentTypes[1];
		$isNullable = $date->isNullable || $format->isNullable || $date->type::getTypeEnum() !== DbTypeEnum::DATETIME;
		$type = in_array($date->type::getTypeEnum(), [DbTypeEnum::DATETIME, DbTypeEnum::VARCHAR], true)
			? new VarcharType()
			: new NullType();

		return new QueryResultField($nodeContent, $type, $isNullable);
	}
}
