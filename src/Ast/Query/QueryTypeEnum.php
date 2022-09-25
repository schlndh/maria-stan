<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

enum QueryTypeEnum: string
{
	/** @see SelectQuery */
	case SELECT = 'SELECT';

	/** @see CombinedSelectQuery */
	case COMBINED_SELECT = 'COMBINED_SELECT';
}
