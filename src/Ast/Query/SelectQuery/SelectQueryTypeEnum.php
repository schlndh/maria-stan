<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

enum SelectQueryTypeEnum: string
{
	/** @see SimpleSelectQuery */
	case SIMPLE = 'SIMPLE';

	/** @see CombinedSelectQuery */
	case COMBINED = 'COMBINED';

	/** @see WithSelectQuery */
	case WITH = 'WITH';
}
