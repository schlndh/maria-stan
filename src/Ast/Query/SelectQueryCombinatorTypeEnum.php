<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

enum SelectQueryCombinatorTypeEnum: string
{
	case UNION = 'UNION';
	case EXCEPT = 'EXCEPT';
	case INTERSECT = 'INTERSECT';
}
