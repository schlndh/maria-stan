<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\CastType\CastType;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class Cast extends BaseFunctionCall
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $expression,
		public readonly CastType $castType,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public function getFunctionName(): string
	{
		return 'CAST';
	}

	/** @inheritDoc */
	public function getArguments(): array
	{
		return [$this->expression, $this->castType];
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::CAST;
	}
}
