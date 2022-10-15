<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\Expr\NonCompositeTimeUnitEnum;
use MariaStan\Parser\Position;

final class TimestampAddDiff extends BaseFunctionCall
{
	/** @param non-empty-array<Expr> $arguments */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $name,
		public readonly NonCompositeTimeUnitEnum $timeUnit,
		public readonly array $arguments,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public function getFunctionName(): string
	{
		return $this->name;
	}

	/** @inheritDoc */
	public function getArguments(): array
	{
		return $this->arguments;
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::TIMESTAMP_ADD_DIFF;
	}
}
