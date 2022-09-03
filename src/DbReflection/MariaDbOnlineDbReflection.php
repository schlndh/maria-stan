<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Analyser\AnalyserErrorMessageBuilder;
use MariaStan\DbReflection\Exception\DatabaseException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\Exception\TableDoesNotExistException;
use MariaStan\DbReflection\Exception\UnexpectedValueException;
use MariaStan\Schema\Column;
use MariaStan\Schema\DbType\DateTimeType;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DecimalType;
use MariaStan\Schema\DbType\EnumType;
use MariaStan\Schema\DbType\FloatType;
use MariaStan\Schema\DbType\IntType;
use MariaStan\Schema\DbType\VarcharType;
use MariaStan\Schema\Table;
use MariaStan\Util\MariaDbErrorCodes;
use MariaStan\Util\MysqliUtil;
use mysqli;
use mysqli_sql_exception;

use function array_combine;
use function array_map;
use function explode;
use function preg_match;
use function trim;

class MariaDbOnlineDbReflection
{
	public function __construct(private readonly mysqli $mysqli)
	{
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table): ?Table
	{
		$tableEsc = MysqliUtil::quoteIdentifier($table);

		try {
			$tableCols = $this->mysqli->query("SHOW FULL COLUMNS FROM {$tableEsc}")->fetch_all(\MYSQLI_ASSOC);
		} catch (mysqli_sql_exception $e) {
			if ($e->getCode() === MariaDbErrorCodes::ER_NO_SUCH_TABLE) {
				throw new TableDoesNotExistException(
					AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage($table),
					$e->getCode(),
					$e,
				);
			}

			throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
		}

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

	/** @throws DbReflectionException */
	private function parseDbType(string $type): DbType
	{
		$origType = $type;
		// get rid of unsigned etc
		[$type] = explode(' ', $type);

		// get rid of size
		[$type] = explode('(', $type);

		switch ($type) {
			case 'varchar':
			case 'tinytext':
			case 'text':
			case 'mediumtext':
			case 'longtext':
			case 'char':
				return new VarcharType();
			case 'int':
			case 'tinyint':
			case 'smallint':
			case 'mediumint':
			case 'bigint':
				return new IntType();
			case 'decimal':
				return new DecimalType();
			case 'float':
			case 'double':
				return new FloatType();
			case 'datetime':
			case 'date':
			case 'time':
			case 'timestamp':
			case 'year':
				return new DateTimeType();
			case 'enum':
				$matches = [];

				// TODO: Do this properly: the enum values themselves could contain "','".
				if (preg_match("/enum\(([^\)]+)\)/i", $origType, $matches)) {
					return new EnumType(explode("','", trim($matches[1], "'")));
				}
				// fall-through intentional: invalid enum type.
			default:
				// TODO: return MixedType instead with some warning?
				throw new UnexpectedValueException("Unrecognized type {$origType}");
		}
	}
}
