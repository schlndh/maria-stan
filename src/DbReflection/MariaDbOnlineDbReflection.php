<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DbReflection\Exception\DatabaseException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Schema\Table;
use MariaStan\Util\MysqliUtil;
use mysqli;
use mysqli_sql_exception;

use function hash;

use const MYSQLI_ASSOC;

class MariaDbOnlineDbReflection implements DbReflection
{
	private readonly string $database;

	/** @var array<string, Table> table name => schema */
	private array $parsedSchemas = [];

	public function __construct(private readonly mysqli $mysqli, private readonly InformationSchemaParser $schemaParser)
	{
		$this->database = MysqliUtil::getDatabaseName($this->mysqli);
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table): Table
	{
		if (isset($this->parsedSchemas[$table])) {
			return $this->parsedSchemas[$table];
		}

		try {
			$stmt = $this->mysqli->prepare('
				SELECT * FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
				ORDER BY ORDINAL_POSITION
			');
			$stmt->execute([$this->database, $table]);
			/** @var array<array<string, scalar|null>> $tableCols */
			$tableCols = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			$stmt = $this->mysqli->prepare('
				SELECT * FROM information_schema.TABLE_CONSTRAINTS tc
				JOIN information_schema.KEY_COLUMN_USAGE kcu
					USING (CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME)
				WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = "FOREIGN KEY"
				ORDER BY CONSTRAINT_NAME, kcu.ORDINAL_POSITION
			');
			$stmt->execute([$this->database, $table]);
			/** @var array<array<string, scalar|null>> $foreignKeys */
			$foreignKeys = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
		} catch (mysqli_sql_exception $e) {
			throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
		}

		return $this->parsedSchemas[$table] = new Table(
			$table,
			$this->schemaParser->parseTableColumns($table, $tableCols),
			$this->schemaParser->parseTableForeignKeys($foreignKeys),
		);
	}

	public function getHash(): string
	{
		return hash('xxh128', MariaDbFileDbReflection::dumpSchema($this->mysqli, $this->database));
	}
}
