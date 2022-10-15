<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\Query\SelectQuery\SelectQuery;

enum QueryTypeEnum: string
{
	/** @see SelectQuery */
	case SELECT = 'SELECT';

	/** @see InsertQuery */
	case INSERT = 'INSERT';
}
