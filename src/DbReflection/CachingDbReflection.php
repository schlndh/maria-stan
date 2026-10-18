<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Analyser\AnalyserErrorBuilder;
use MariaStan\DbReflection\Exception\ViewDoesNotExistException;
use MariaStan\Schema\Table;
use MariaStan\Util\MariaDbErrorCodes;

class CachingDbReflection implements DbReflection
{
	/** @var array<int|string, array<int|string, Table>> database => table name => schema */
	private array $foundSchemas = [];

	/** @var ?array<int|string, array<int|string, string>> DB name => view name => definition */
	private ?array $viewDefinitions = null;
	private ?string $hash = null;

	public function __construct(private readonly DbReflection $dbReflection)
	{
	}

	public function getDefaultDatabase(): string
	{
		return $this->dbReflection->getDefaultDatabase();
	}

	public function findTableSchema(string $table, ?string $database = null): Table
	{
		return $this->foundSchemas[$database ?? $this->getDefaultDatabase()][$table]
			??= $this->dbReflection->findTableSchema($table, $database);
	}

	public function findViewDefinition(string $view, ?string $database = null): string
	{
		return $this->getViewDefinitions()[$database ?? $this->getDefaultDatabase()][$view]
			?? throw new ViewDoesNotExistException(
				AnalyserErrorBuilder::createTableDoesntExistErrorMessage($view, $database),
				MariaDbErrorCodes::ER_UNKNOWN_TABLE,
			);
	}

	/** @inheritDoc */
	public function getViewDefinitions(): array
	{
		return $this->viewDefinitions ??= $this->dbReflection->getViewDefinitions();
	}

	public function getHash(): string
	{
		return $this->hash ??= $this->dbReflection->getHash();
	}
}
