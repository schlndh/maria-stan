<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\GroupBy;
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Parser\Position;

final class SelectQuery extends BaseNode implements Query
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
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::SELECT;
	}
}
