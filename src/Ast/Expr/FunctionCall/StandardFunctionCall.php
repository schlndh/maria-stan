<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class StandardFunctionCall extends BaseFunctionCall
{
	/** @param array<Expr> $arguments */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $name,
		public readonly array $arguments,
		public readonly bool $isDistinct = false,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public function getFunctionName(): string
	{
		return $this->name;
	}

	/** @return array<Expr> */
	public function getArguments(): array
	{
		return $this->arguments;
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::STANDARD;
	}
}
