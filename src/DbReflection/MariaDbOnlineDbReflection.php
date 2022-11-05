<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DbReflection\Exception\DatabaseException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\Schema\Table;
use MariaStan\Util\MysqliUtil;
use mysqli;
use mysqli_sql_exception;

use const MYSQLI_ASSOC;

class MariaDbOnlineDbReflection implements DbReflection
{
	private readonly string $database;

	/** @var array<string, Table> table name => schema */
	private array $parsedSchemas;

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
			$stmt = $this->mysqli->prepare(
				'SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
			);
			$stmt->execute([$this->database, $table]);
			$tableCols = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
		} catch (mysqli_sql_exception $e) {
			throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
		}

		return $this->parsedSchemas[$table] = $this->schemaParser->parseTableSchema($table, $tableCols);
	}
}
