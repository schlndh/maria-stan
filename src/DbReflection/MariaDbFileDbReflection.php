<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Analyser\AnalyserErrorBuilder;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\Exception\UnexpectedValueException;
use MariaStan\DbReflection\Exception\ViewDoesNotExistException;
use MariaStan\Schema\Table;
use MariaStan\Util\MariaDbErrorCodes;

use function array_column;
use function array_fill_keys;
use function array_map;
use function file_get_contents;
use function hash;
use function is_array;
use function is_string;
use function serialize;
use function unserialize;

use const MYSQLI_ASSOC;

/**
 * @phpcs:ignore
 * @phpstan-type SchemaDump array{
 *     __version: int,
 *     databases: array<string, array{
 *         tables: array<string, array{
 *             columns: array<array<string, scalar|null>>,
 *             foreign_keys: array<array<string, scalar|null>>
 *	       }>,
 *         views: array<string, array{definition: string}>
 *     }>
 * }
 */
class MariaDbFileDbReflection implements DbReflection
{
	private const DUMP_VERSION = 3;

	/** @var SchemaDump */
	private readonly array $schemaDump;

	/** @var array<string, Table> table name => schema */
	private array $parsedSchemas = [];

	public function __construct(
		string $dumpFile,
		private readonly string $defaultDatabase,
		private readonly InformationSchemaParser $schemaParser,
	) {
		$contents = file_get_contents($dumpFile);

		if ($contents === false) {
			throw new \InvalidArgumentException("File {$dumpFile} is not readable.");
		}

		$dump = unserialize($contents, ['allowed_classes' => false]);

		if (! is_array($dump) || $dump['__version'] > self::DUMP_VERSION || ! is_array($dump['databases'] ?? null)) {
			throw new \InvalidArgumentException(
				'Dumped schema was not recognized. Dump the schema again with current version of MariaStan.',
			);
		}

		foreach ($dump['databases'] as $database) {
			if (is_array($database) && is_array($database['tables'] ?? null) && is_array($database['views'] ?? null)) {
				continue;
			}

			throw new \InvalidArgumentException(
				'Dumped schema was not recognized. Dump the schema again with current version of MariaStan.',
			);
		}

		/** @phpstan-var SchemaDump $dump */
		$this->schemaDump = $dump;
	}

	public function getDefaultDatabase(): string
	{
		return $this->defaultDatabase;
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table, ?string $database = null): Table
	{
		$tableDump = $this->schemaDump['databases'][$database ?? $this->defaultDatabase]['tables'][$table] ?? [];

		if (! is_array($tableDump)) {
			throw new UnexpectedValueException(
				"Dumped schema for table {$table} was not recognized."
				. " Dump the schema again with current version of MariaStan.",
			);
		}

		$cols = $tableDump['columns'] ?? [];

		if (! is_array($cols)) {
			throw new UnexpectedValueException(
				"Dumped schema for table {$table} was not recognized."
				. " Dump the schema again with current version of MariaStan.",
			);
		}

		$foreignKeys = $tableDump['foreign_keys'] ?? [];

		if (! is_array($foreignKeys)) {
			throw new UnexpectedValueException(
				"Dumped schema for table {$table} was not recognized."
				. " Dump the schema again with current version of MariaStan.",
			);
		}

		return $this->parsedSchemas[$table] ??= new Table(
			$table,
			$this->schemaParser->parseTableColumns($table, $cols),
			$this->schemaParser->parseTableForeignKeys($foreignKeys),
		);
	}

	public function findViewDefinition(string $view, ?string $database = null): string
	{
		return $this->schemaDump['databases'][$database ?? $this->defaultDatabase]['views'][$view]['definition']
			?? throw new ViewDoesNotExistException(
				AnalyserErrorBuilder::createTableDoesntExistErrorMessage($view, $database),
				MariaDbErrorCodes::ER_UNKNOWN_TABLE,
			);
	}

	/** @inheritDoc */
	public function getViewDefinitions(): array
	{
		return array_map(
			static fn (array $d) => array_map(static fn (array $v) => $v['definition'], $d['views']),
			$this->schemaDump['databases'],
		);
	}

	public function getHash(): string
	{
		return hash('xxh128', serialize($this->schemaDump));
	}

	/** @param string|array<string>|null $databases null = all */
	public static function dumpSchema(\mysqli $db, string|array|null $databases): string
	{
		if (is_string($databases)) {
			$databases = [$databases];
		} elseif ($databases === null) {
			$result = $db->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');
			$databases = array_column($result->fetch_all(), 0);
		}

		$result = [
			'__version' => self::DUMP_VERSION,
			'databases' => array_fill_keys($databases, [
				'tables' => [],
				'views' => [],
			]),
		];

		foreach ($databases as $database) {
			$stmt = $db->prepare('
				SELECT * FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = ?
				ORDER BY TABLE_NAME, ORDINAL_POSITION
			');
			$stmt->execute([$database]);
			$columns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			/** @var array<string, scalar|null> $col */
			foreach ($columns as $col) {
				$result['databases'][$database]['tables'][$col['TABLE_NAME']]['columns'][] = $col;
			}

			$stmt = $db->prepare('
				SELECT * FROM information_schema.TABLE_CONSTRAINTS tc
				JOIN information_schema.KEY_COLUMN_USAGE kcu
					USING (CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME)
				WHERE TABLE_SCHEMA = ? AND tc.CONSTRAINT_TYPE = "FOREIGN KEY"
					/* This happens when there is a FK and UNIQUE with same name. */
					AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
				ORDER BY TABLE_NAME, CONSTRAINT_NAME, kcu.ORDINAL_POSITION
			');
			$stmt->execute([$database]);
			$foreignKeys = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			/** @var array<string, scalar|null> $fk */
			foreach ($foreignKeys as $fk) {
				$result['databases'][$database]['tables'][$fk['TABLE_NAME']]['foreign_keys'][] = $fk;
			}

			$stmt = $db->prepare('
				SELECT TABLE_SCHEMA, TABLE_NAME, VIEW_DEFINITION
				FROM information_schema.VIEWS
				WHERE TABLE_SCHEMA = ?
			');
			$stmt->execute([$database]);
			$views = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			foreach ($views as $view) {
				$result['databases'][$view['TABLE_SCHEMA']]['views'][$view['TABLE_NAME']]
					= ['definition' => $view['VIEW_DEFINITION']];
			}
		}

		return serialize($result);
	}
}
