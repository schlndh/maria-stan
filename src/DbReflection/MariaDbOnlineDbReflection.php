<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Analyser\AnalyserErrorBuilder;
use MariaStan\DbReflection\Exception\DatabaseException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\Exception\ViewDoesNotExistException;
use MariaStan\Schema\Table;
use MariaStan\Util\MariaDbErrorCodes;
use mysqli;
use mysqli_sql_exception;

use function hash;

use const MYSQLI_ASSOC;

class MariaDbOnlineDbReflection implements DbReflection
{
	/** @var array<string, array<string, Table>> database => table name => schema */
	private array $parsedSchemas = [];

	public function __construct(
		private readonly mysqli $mysqli,
		private readonly string $defaultDatabase,
		private readonly InformationSchemaParser $schemaParser,
	) {
	}

	public function getDefaultDatabase(): string
	{
		return $this->defaultDatabase;
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table, ?string $database = null): Table
	{
		$database ??= $this->defaultDatabase;

		if (isset($this->parsedSchemas[$database][$table])) {
			return $this->parsedSchemas[$database][$table];
		}

		try {
			$stmt = $this->mysqli->prepare('
				SELECT * FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
				ORDER BY ORDINAL_POSITION
			');
			$stmt->execute([$database, $table]);
			/** @var array<array<string, scalar|null>> $tableCols */
			$tableCols = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			$stmt = $this->mysqli->prepare('
				SELECT * FROM information_schema.TABLE_CONSTRAINTS tc
				JOIN information_schema.KEY_COLUMN_USAGE kcu
					USING (CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME)
				WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = "FOREIGN KEY"
					/* This happens when there is a FK and UNIQUE with same name. */
					AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
				ORDER BY CONSTRAINT_NAME, kcu.ORDINAL_POSITION
			');
			$stmt->execute([$database, $table]);
			/** @var array<array<string, scalar|null>> $foreignKeys */
			$foreignKeys = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
		} catch (mysqli_sql_exception $e) {
			throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
		}

		return $this->parsedSchemas[$database][$table] = new Table(
			$table,
			$this->schemaParser->parseTableColumns($table, $tableCols),
			$this->schemaParser->parseTableForeignKeys($foreignKeys),
		);
	}

	public function findViewDefinition(string $view, ?string $database = null): string
	{
		return $this->getViewDefinitions()[$database ?? $this->defaultDatabase][$view]
			?? throw new ViewDoesNotExistException(
				AnalyserErrorBuilder::createTableDoesntExistErrorMessage($view, $database),
				MariaDbErrorCodes::ER_UNKNOWN_TABLE,
			);
	}

	/** @inheritDoc */
	public function getViewDefinitions(): array
	{
		$stmt = $this->mysqli->prepare('
			SELECT TABLE_SCHEMA, TABLE_NAME, VIEW_DEFINITION
			FROM information_schema.VIEWS
		');
		$stmt->execute();
		$views = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
		$result = [];

		foreach ($views as $view) {
			$result[$view['TABLE_SCHEMA']][$view['TABLE_NAME']] = $view['VIEW_DEFINITION'];
		}

		return $result;
	}

	public function getHash(): string
	{
		return hash('xxh128', MariaDbFileDbReflection::dumpSchema($this->mysqli, null));
	}
}
