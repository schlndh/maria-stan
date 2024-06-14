<?php

declare(strict_types=1);

namespace MariaStan\Analyser\PlaceholderTypeProvider;

use MariaStan\Ast\Expr\Placeholder;
use MariaStan\Schema\DbType\DbType;

/**
 * It is used to narrow down placeholder types. Note that the DB type depends on the way that the value is bound to the
 * placeholder, and not necessarily on the type of the PHP value. For example {@see mysqli_stmt::execute()}
 * and {@see \PDOStatement::bindParam()} bind the parameters as strings by default.
 */
interface PlaceholderTypeProvider
{
	public function getPlaceholderDbType(Placeholder $placeholder): DbType;

	public function isPlaceholderNullable(Placeholder $placeholder): bool;
}
