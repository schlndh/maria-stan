<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Analyser\AnalyserErrorBuilder;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\Exception\UnexpectedValueException;
use MariaStan\DbReflection\Exception\ViewDoesNotExistException;
use MariaStan\Schema\Table;
use MariaStan\Util\MariaDbErrorCodes;
use MariaStan\Util\TypeUtil;

use function array_column;
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
 *     databases: array<array-key, array{
 *         tables: array<array-key, array{
 *             columns?: array<array<array-key, scalar|null>>,
 *             foreign_keys?: array<array<array-key, scalar|null>>
 *	       }>,
 *         views: array<array-key, array{definition: string}>
 *     }>
 * }
 */
final class MariaDbFileDbReflection implements DbReflection
{
	private const DUMP_VERSION = 3;

	/** @var SchemaDump */
	private readonly array $schemaDump;

	/** @param string|SchemaDump $dumpFile path to dump file, or already loaded and checked schema dump */
	public function __construct(
		string|array $dumpFile,
		private readonly string $defaultDatabase,
		private readonly InformationSchemaParser $schemaParser,
		private readonly SequenceEngineHandler $sequenceEngineHandler,
	) {
		if (is_array($dumpFile)) {
			$this->schemaDump = $dumpFile;

			return;
		}

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

	/** @param string|array<string>|null $databases null = all */
	public static function createFromLiveDb(
		\mysqli $db,
		string $defaultDatabase,
		InformationSchemaParser $schemaParser,
		SequenceEngineHandler $sequenceEngineHandler,
		string|array|null $databases = null,
	): self {
		return new self(
			self::dumpRawSchema($db, $databases),
			$defaultDatabase,
			$schemaParser,
			$sequenceEngineHandler,
		);
	}

	public function getDefaultDatabase(): string
	{
		return $this->defaultDatabase;
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table, ?string $database = null): Table
	{
		$origDatabase = $database;
		$database ??= $this->defaultDatabase;
		$sequence = $this->sequenceEngineHandler->handleSequenceTable($table, $database);

		if ($sequence !== null) {
			return $sequence;
		}

		$tableDump = $this->schemaDump['databases'][$database]['tables'][$table] ?? [];

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

		return new Table(
			$table,
			$database,
			$this->schemaParser->parseTableColumns($table, $cols, $origDatabase),
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

	/**
	 * @param string|array<string>|null $databases null = all
	 * @return SchemaDump
	 */
	private static function dumpRawSchema(\mysqli $db, string|array|null $databases): array
	{
		if (is_string($databases)) {
			$databases = [$databases];
		} elseif ($databases === null) {
			$result = $db->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');
			/** @var list<string> $databases */
			$databases = array_column($result->fetch_all(), 0);
		}

		$result = [
			'__version' => self::DUMP_VERSION,
			'databases' => [],
		];

		foreach ($databases as $database) {
			$tables = [];
			$views = [];
			$stmt = $db->prepare('
				SELECT * FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = ?
				ORDER BY TABLE_NAME, ORDINAL_POSITION
			');
			$stmt->execute([$database]);
			$columns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			/** @var array<string, scalar|null> $col */
			foreach ($columns as $col) {
				$tableName = TypeUtil::getStringValue($col, 'TABLE_NAME');
				$tables[$tableName]['columns'][] = $col;
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
				$tableName = TypeUtil::getStringValue($fk, 'TABLE_NAME');
				$tables[$tableName]['foreign_keys'][] = $fk;
			}

			$stmt = $db->prepare('
				SELECT TABLE_SCHEMA, TABLE_NAME, VIEW_DEFINITION
				FROM information_schema.VIEWS
				WHERE TABLE_SCHEMA = ?
			');
			$stmt->execute([$database]);
			$viewDefinitions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			foreach ($viewDefinitions as $view) {
				$tableName = TypeUtil::getStringValue($view, 'TABLE_NAME');
				$views[$tableName]
					= ['definition' => TypeUtil::getStringValue($view, 'VIEW_DEFINITION')];
			}

			$result['databases'][$database] = [
				'tables' => $tables,
				'views' => $views,
			];
		}

		return $result;
	}

	/** @param string|array<string>|null $databases null = all */
	public static function dumpSchema(\mysqli $db, string|array|null $databases): string
	{
		return serialize(self::dumpRawSchema($db, $databases));
	}
}
