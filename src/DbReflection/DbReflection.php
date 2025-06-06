<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Schema\Table;

interface DbReflection
{
	/** @throws DbReflectionException */
	public function findTableSchema(string $table): Table;

	/** @throws DbReflectionException */
	public function findViewDefinition(string $view): string;

	/**
	 * @return array<string, array<string, string>> DB name => view name => definition
	 * @throws DbReflectionException
	 */
	public function getViewDefinitions(): array;

	/**
	 * Get DB reflection has. It should change when the underlying schema changes.
	 *
	 * @throws DbReflectionException
	 */
	public function getHash(): string;
}
