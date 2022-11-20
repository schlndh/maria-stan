<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use MariaStan\Analyser\QueryResultField;
use MariaStan\Ast\Expr\FunctionCall\FunctionCall;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\MixedType;
use MariaStan\Schema\DbType\VarcharType;

use function count;

final class IfFunction implements FunctionInfo
{
	/** @inheritDoc */
	public function getSupportedFunctionNames(): array
	{
		return ['IF'];
	}

	public function getFunctionType(): FunctionTypeEnum
	{
		return FunctionTypeEnum::SIMPLE;
	}

	public function checkSyntaxErrors(FunctionCall $functionCall): void
	{
		$args = $functionCall->getArguments();
		$argCount = count($args);

		if ($argCount !== 3) {
			throw new ParserException(
				FunctionInfoHelper::createArgumentCountErrorMessageFixed(
					$functionCall->getFunctionName(),
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
		$then = $argumentTypes[1];
		$else = $argumentTypes[2];
		$isNullable = $then->isNullable || $else->isNullable;

		$lt = $then->type::getTypeEnum();
		$rt = $else->type::getTypeEnum();
		$typesInvolved = [
			$lt->value => 1,
			$rt->value => 1,
		];

		if (
			$then->type::getTypeEnum() === $else->type::getTypeEnum()
			// We'd have to check that the cases are the same.
			&& $then->type::getTypeEnum() !== DbTypeEnum::ENUM
		) {
			$type = $then->type;
		} elseif (isset($typesInvolved[DbTypeEnum::NULL->value])) {
			$type = $lt === DbTypeEnum::NULL
				? $else->type
				: $then->type;
		} elseif (isset($typesInvolved[DbTypeEnum::MIXED->value])) {
			$type = new MixedType();
		} elseif (isset($typesInvolved[DbTypeEnum::VARCHAR->value])) {
			$type = new VarcharType();
		} elseif (isset($typesInvolved[DbTypeEnum::DATETIME->value])) {
			$type = new VarcharType();
		} elseif (isset($typesInvolved[DbTypeEnum::FLOAT->value])) {
			$type = new FloatType();
		} elseif (isset($typesInvolved[DbTypeEnum::DECIMAL->value])) {
			$type = new DecimalType();
		} elseif (isset($typesInvolved[DbTypeEnum::INT->value])) {
			$type = new IntType();
		} else {
			$type = new MixedType();
		}

		return new QueryResultField($nodeContent, $type, $isNullable);
	}
}
