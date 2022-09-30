<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

enum ColumnResolverFieldBehaviorEnum: string
{
	// SELECT ...
	case FIELD_LIST = 'FIELD_LIST';
	case GROUP_BY = 'GROUP_BY';
	case HAVING = 'HAVING';
}
