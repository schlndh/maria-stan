<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Schema\Table;

interface DbReflection
{
	/** @throws DbReflectionException */
	public function findTableSchema(string $table): Table;
}
