<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Limit;
use MariaStan\Ast\OrderBy;
use MariaStan\Parser\Position;

final class CombinedSelectQuery extends BaseNode implements Query
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly SelectQueryCombinatorTypeEnum $combinator,
		public readonly bool $isDistinct,
		public readonly SelectQuery|CombinedSelectQuery $left,
		public readonly SelectQuery|CombinedSelectQuery $right,
		public readonly ?OrderBy $orderBy = null,
		public readonly ?Limit $limit = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::COMBINED_SELECT;
	}
}
