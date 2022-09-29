<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\QueryTypeEnum;

abstract class BaseSelectQuery extends BaseNode implements SelectQuery
{
	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::SELECT;
	}
}
