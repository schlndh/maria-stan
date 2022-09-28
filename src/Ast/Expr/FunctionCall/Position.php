<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position as ParserPosition;

final class Position extends BaseFunctionCall implements FunctionCall
{
	public function __construct(
		ParserPosition $startPosition,
		ParserPosition $endPosition,
		public readonly Expr $substr,
		public readonly Expr $str,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public function getFunctionName(): string
	{
		return 'POSITION';
	}

	/** @inheritDoc */
	public function getArguments(): array
	{
		return [$this->substr, $this->str];
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::POSITION;
	}
}
