<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Schema\Table;

interface DbReflection
{
	/** @throws DbReflectionException */
	public function findTableSchema(string $table): Table;

	/**
	 * Get DB reflection has. It should change when the underlying schema changes.
	 *
	 * @throws DbReflectionException
	 */
	public function getHash(): string;
}
