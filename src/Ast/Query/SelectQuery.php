<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Parser\Position;

final class SelectQuery extends BaseNode implements Query
{
	/**
	 * @param non-empty-array<SelectExpr> $select
	 * @param array<TableReference>|null $from
	 */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly array $select,
		public readonly ?array $from,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::SELECT;
	}
}
