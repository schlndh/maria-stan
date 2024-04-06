<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\ExprWithDirection;
use MariaStan\Ast\Limit;
use MariaStan\Ast\OrderBy;
use MariaStan\Parser\Position;

use function array_filter;
use function array_map;
use function array_merge;

final class JsonArrayAgg extends BaseFunctionCall
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly Expr $expression,
		public readonly ?OrderBy $orderBy = null,
		public readonly ?Limit $limit = null,
		public readonly bool $isDistinct = false,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public function getFunctionName(): string
	{
		return 'JSON_ARRAYAGG';
	}

	/** @inheritDoc */
	public function getArguments(): array
	{
		return array_merge(
			[$this->expression],
			array_map(
				static fn (ExprWithDirection $e) => $e->expr,
				$this->orderBy->expressions ?? [],
			),
			array_filter([
				$this->limit?->count,
				$this->limit?->offset,
			], static fn (mixed $v) => $v !== null),
		);
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::JSON_ARRAYAGG;
	}
}
