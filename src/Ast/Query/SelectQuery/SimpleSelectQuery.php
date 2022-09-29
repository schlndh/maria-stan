<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\GroupBy;
use MariaStan\Ast\Limit;
use MariaStan\Ast\Lock\SelectLock;
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Parser\Position;

final class SimpleSelectQuery extends BaseSelectQuery
{
	/** @param non-empty-array<SelectExpr> $select */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly array $select,
		public readonly ?TableReference $from = null,
		public readonly ?Expr $where = null,
		public readonly ?GroupBy $groupBy = null,
		public readonly ?Expr $having = null,
		public readonly ?OrderBy $orderBy = null,
		public readonly ?Limit $limit = null,
		public readonly bool $isDistinct = false,
		public readonly ?SelectLock $lock = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getSelectQueryType(): SelectQueryTypeEnum
	{
		return SelectQueryTypeEnum::SIMPLE;
	}
}
