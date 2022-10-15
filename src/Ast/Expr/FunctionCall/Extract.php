<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\TimeUnitEnum;
use MariaStan\Parser\Position;

final class Extract extends BaseFunctionCall
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly TimeUnitEnum $timeUnit,
		public readonly Expr $from,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public function getFunctionName(): string
	{
		return 'EXTRACT';
	}

	/** @inheritDoc */
	public function getArguments(): array
	{
		return [$this->from];
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::EXTRACT;
	}
}
