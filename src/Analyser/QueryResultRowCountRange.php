<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Ast\Expr\ExprTypeEnum;
use MariaStan\Ast\Expr\LiteralInt;
use MariaStan\Ast\Limit;

use function assert;
use function max;
use function min;

final class QueryResultRowCountRange
{
	/**
	 * @phpstan-param positive-int|0 $min
	 * @phpstan-param positive-int|0|null $max null => unlimited
	 */
	public function __construct(public readonly int $min, public readonly ?int $max)
	{
	}

	/** @phpstan-param positive-int|0 $count */
	public static function createFixed(int $count): self
	{
		return new self($count, $count);
	}

	public function applyLimitClause(?Limit $limit): self
	{
		if ($limit === null || $this->min === 0) {
			return $this;
		}

		$count = $limit->count;
		$offset = $limit->offset;
		$newMin = $this->min;
		$newMax = $this->max;

		if ($offset !== null) {
			if ($offset::getExprType() === ExprTypeEnum::LITERAL_INT) {
				assert($offset instanceof LiteralInt);
				assert($offset->value >= 0);
				$newMin = max(0, $newMin - $offset->value);

				if ($newMax !== null) {
					$newMax = max(0, $newMax - $offset->value);
				}
			} else {
				$newMin = 0;
			}
		}

		if ($count::getExprType() === ExprTypeEnum::LITERAL_INT) {
			assert($count instanceof LiteralInt);
			assert($count->value >= 0);
			$newMin = min($newMin, $count->value);
			$newMax = min($newMax ?? $count->value, $count->value);
		} else {
			$newMin = 0;
		}

		return new self($newMin, $newMax);
	}

	public function applyWhere(?ExprTypeResult $whereResult): self
	{
		if (
			$whereResult === null
			|| ($whereResult->knowledgeBase !== null && $whereResult->knowledgeBase->truthiness)
		) {
			return $this;
		}

		return new self(0, $this->max);
	}
}
