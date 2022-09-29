<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

use MariaStan\Ast\Query\Query;

interface SelectQuery extends Query
{
	public static function getSelectQueryType(): SelectQueryTypeEnum;
}
