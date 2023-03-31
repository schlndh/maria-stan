<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

use MariaStan\Ast\Limit;
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\Query\SelectQueryCombinatorTypeEnum;
use MariaStan\Parser\Position;

final class CombinedSelectQuery extends BaseSelectQuery
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly SelectQueryCombinatorTypeEnum $combinator,
		public readonly SimpleSelectQuery|self $left,
		public readonly SimpleSelectQuery|self $right,
		public readonly ?OrderBy $orderBy = null,
		public readonly ?Limit $limit = null,
		public readonly bool $isDistinct = true,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getSelectQueryType(): SelectQueryTypeEnum
	{
		return SelectQueryTypeEnum::COMBINED;
	}
}
