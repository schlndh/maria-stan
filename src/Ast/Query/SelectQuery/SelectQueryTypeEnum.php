<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

enum SelectQueryTypeEnum: string
{
	/** @see SimpleSelectQuery */
	case SIMPLE = 'SIMPLE';

	/** @see CombinedSelectQuery */
	case COMBINED = 'COMBINED';

	/** @see TableValueConstructorSelectQuery */
	case TABLE_VALUE_CONSTRUCTOR = 'TABLE_VALUE_CONSTRUCTOR';

	/** @see WithSelectQuery */
	case WITH = 'WITH';
}
