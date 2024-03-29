<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\Query\SelectQuery\SelectQuery;

enum QueryTypeEnum: string
{
	/** @see DeleteQuery */
	case DELETE = 'DELETE';

	/** @see InsertQuery */
	case INSERT = 'INSERT';

	/** @see SelectQuery */
	case SELECT = 'SELECT';

	/** @see ReplaceQuery */
	case REPLACE = 'REPLACE';

	/** @see TruncateQuery */
	case TRUNCATE = 'TRUNCATE';

	/** @see UpdateQuery */
	case UPDATE = 'UPDATE';
}
