<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class Trim extends BaseFunctionCall implements FunctionCall
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $str,
		public readonly ?Expr $remstr = null,
		public readonly TrimTypeEnum $trimType = TrimTypeEnum::BOTH,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public function getFunctionName(): string
	{
		return 'TRIM';
	}

	/** @inheritDoc */
	public function getArguments(): array
	{
		return $this->remstr !== null
			? [$this->remstr, $this->str]
			: [$this->str];
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::TRIM;
	}
}
