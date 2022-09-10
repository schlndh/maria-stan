<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;

interface FunctionCall extends Expr
{
	public function getFunctionName(): string;

	/** @return array<Expr> */
	public function getArguments(): array;

	public static function getFunctionCallType(): FunctionCallTypeEnum;
}
