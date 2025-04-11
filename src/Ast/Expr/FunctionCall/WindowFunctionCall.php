<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\ExprWithDirection;
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\WindowFrame;
use MariaStan\Parser\Position;

use function array_filter;
use function array_map;
use function array_merge;

final class WindowFunctionCall extends BaseFunctionCall
{
	/** @param array<Expr>|null $partitionBy */
	public function __construct(
		Position $endPosition,
		public readonly FunctionCall $functionCall,
		public readonly ?array $partitionBy,
		public readonly ?OrderBy $orderBy,
		public readonly ?WindowFrame $frame,
	) {
		parent::__construct($this->functionCall->getStartPosition(), $endPosition);
	}

	public function getFunctionName(): string
	{
		return $this->functionCall->getFunctionName();
	}

	/** @return array<Expr> */
	public function getArguments(): array
	{
		return array_merge(
			$this->functionCall->getArguments(),
			$this->partitionBy ?? [],
			array_map(
				static fn (ExprWithDirection $e) => $e->expr,
				$this->orderBy->expressions ?? [],
			),
			array_filter([
				$this->frame?->from->expression,
				$this->frame?->to?->expression,
			], static fn (mixed $v) => $v !== null),
		);
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::WINDOW;
	}
}
