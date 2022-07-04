<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Ast\SelectExpr\SelectExpr;

final class SelectQuery implements Query
{
	/**
	 * @param non-empty-array<SelectExpr> $select
	 * @param array<TableReference>|null $from
	 */
	public function __construct(public readonly array $select, public readonly ?array $from)
	{
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::SELECT;
	}
}
