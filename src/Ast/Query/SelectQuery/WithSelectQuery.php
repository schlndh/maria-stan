<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

use MariaStan\Ast\CommonTableExpression;
use MariaStan\Parser\Position;

final class WithSelectQuery extends BaseSelectQuery
{
	/** @param non-empty-array<CommonTableExpression> $commonTableExpressions */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly array $commonTableExpressions,
		public readonly SimpleSelectQuery|CombinedSelectQuery $selectQuery,
		public readonly bool $allowRecursive = false,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getSelectQueryType(): SelectQueryTypeEnum
	{
		return SelectQueryTypeEnum::WITH;
	}
}
