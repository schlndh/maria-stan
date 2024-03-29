<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

enum ColumnResolverFieldBehaviorEnum: string
{
	// SELECT ..., also used for WHERE
	case FIELD_LIST = 'FIELD_LIST';
	case GROUP_BY = 'GROUP_BY';
	case HAVING = 'HAVING';
	case ORDER_BY = 'ORDER_BY';

	// left side of assignment (e.g. ON DUPLICATE KEY UPDATE a = ...)
	case ASSIGNMENT = 'ASSIGNMENT';
}
