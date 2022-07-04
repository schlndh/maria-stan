<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\Exception\UnexpectedValueException;
use MariaStan\Schema\Column;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use MariaStan\Schema\Table;
use MariaStan\Util\MysqliUtil;
use mysqli;

use function array_combine;
use function array_map;
use function explode;

class MariaDbOnlineDbReflection
{
	public function __construct(private readonly mysqli $mysqli)
	{
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table): ?Table
	{
		$tableEsc = MysqliUtil::quoteIdentifier($table);
		$tableCols = $this->mysqli->query("SHOW FULL COLUMNS FROM {$tableEsc}")->fetch_all(\MYSQLI_ASSOC);
		$columns = array_map(
			$this->createColumnSchema(...),
			$tableCols,
		);
		$columns = array_combine(
			array_map(
				static fn (Column $c) => $c->name,
				$columns,
			),
			$columns,
		);

		return new Table($table, $columns);
	}

	/**
	 * @param array<string, ?string> $showColumsnRow key => value
	 * @throws DbReflectionException
	 */
	private function createColumnSchema(array $showColumsnRow): Column
	{
		return new Column(
			$showColumsnRow['Field'],
			$this->parseDbType($showColumsnRow['Type']),
			match ($showColumsnRow['Null']) {
				'YES' => true,
				'NO' => false,
				default => throw new UnexpectedValueException("Expected YES/NO, got {$showColumsnRow['Null']}"),
			},
		);
	}

	private function parseDbType(string $type): DbType
	{
		$origType = $type;
		// get rid of unsigned etc
		[$type] = explode(' ', $type);

		// get rid of size
		[$type] = explode('(', $type);

		switch ($type) {
			case 'varchar':
				return new VarcharType();
			case 'int':
				return new IntType();
			default:
				throw new UnexpectedValueException("Unrecognized type {$origType}");
		}
	}
}
