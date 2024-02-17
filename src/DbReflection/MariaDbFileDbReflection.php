<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\Exception\UnexpectedValueException;
use MariaStan\Schema\Table;

use function file_get_contents;
use function is_array;
use function serialize;
use function unserialize;

class MariaDbFileDbReflection implements DbReflection
{
	private const DUMP_VERSION = 2;

	/** @var array{__version: int, tables: array<string, array{columns: array<array<string, scalar|null>>}>} */
	private readonly array $schemaDump;

	/** @var array<string, Table> table name => schema */
	private array $parsedSchemas;

	public function __construct(string $dumpFile, private readonly InformationSchemaParser $schemaParser)
	{
		$contents = file_get_contents($dumpFile);

		if ($contents === false) {
			throw new \InvalidArgumentException("File {$dumpFile} is not readable.");
		}

		$dump = unserialize($contents, ['allowed_classes' => false]);

		if (! is_array($dump) || $dump['__version'] > self::DUMP_VERSION || ! is_array($dump['tables'] ?? null)) {
			throw new \InvalidArgumentException(
				'Dumped schema was not recognized. Dump the schema again with current version of MariaStan.',
			);
		}

		$this->schemaDump = $dump;
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table): Table
	{
		$tableDump = $this->schemaDump['tables'][$table] ?? [];

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

	public static function dumpSchema(\mysqli $db, string $database): string
	{
		$result = [
			'__version' => self::DUMP_VERSION,
			'tables' => [],
		];

		$stmt = $db->prepare('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
		$stmt->execute([$database]);
		$columns = $stmt->get_result()->fetch_all(\MYSQLI_ASSOC);

		foreach ($columns as $col) {
			$result['tables'][$col['TABLE_NAME']]['columns'][] = $col;
		}

		$stmt = $db->prepare('
			SELECT * FROM information_schema.TABLE_CONSTRAINTS tc
			JOIN information_schema.KEY_COLUMN_USAGE kcu
				USING (CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME)
			WHERE TABLE_SCHEMA = ? AND tc.CONSTRAINT_TYPE = "FOREIGN KEY"
		');
		$stmt->execute([$database]);
		$foreignKeys = $stmt->get_result()->fetch_all(\MYSQLI_ASSOC);

		foreach ($foreignKeys as $fk) {
			$result['tables'][$fk['TABLE_NAME']]['foreign_keys'][] = $fk;
		}

		return serialize($result);
	}
}
