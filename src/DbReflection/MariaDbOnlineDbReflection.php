<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Analyser\AnalyserErrorMessageBuilder;
use MariaStan\Ast\Expr\Expr;
use MariaStan\DbReflection\Exception\DatabaseException;
use MariaStan\DbReflection\Exception\DbReflectionException;
use MariaStan\DbReflection\Exception\TableDoesNotExistException;
use MariaStan\DbReflection\Exception\UnexpectedValueException;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\Parser\MariaDbParser;
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
use mysqli;
use mysqli_sql_exception;

use function array_combine;
use function array_map;
use function assert;
use function count;
use function explode;
use function is_string;
use function preg_match;
use function stripos;
use function trim;

use const MYSQLI_ASSOC;

class MariaDbOnlineDbReflection
{
	private readonly string $database;

	public function __construct(private readonly mysqli $mysqli, private readonly MariaDbParser $parser)
	{
		$db = $this->mysqli->query('SELECT DATABASE()')->fetch_column();
		assert(is_string($db));

		$this->database = $db;
	}

	/** @throws DbReflectionException */
	public function findTableSchema(string $table): Table
	{
		try {
			$stmt = $this->mysqli->prepare(
				'SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
			);
			$stmt->execute([$this->database, $table]);
			$tableCols = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			if (count($tableCols) === 0) {
				throw new TableDoesNotExistException(
					AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage($table),
					MariaDbErrorCodes::ER_NO_SUCH_TABLE,
				);
			}
		} catch (mysqli_sql_exception $e) {
			throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
		}

		$columns = array_map(
			fn (array $row) => $this->createColumnSchema($table, $row),
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
	 * @param array<string, ?string> $showColumnsRow key => value
	 * @throws DbReflectionException
	 */
	private function createColumnSchema(string $table, array $showColumnsRow): Column
	{
		assert(isset($showColumnsRow['COLUMN_NAME'], $showColumnsRow['COLUMN_TYPE'], $showColumnsRow['IS_NULLABLE']));

		return new Column(
			$showColumnsRow['COLUMN_NAME'],
			$this->parseDbType($showColumnsRow['COLUMN_TYPE']),
			match ($showColumnsRow['IS_NULLABLE']) {
				'YES' => true,
				'NO' => false,
				default => throw new UnexpectedValueException("Expected YES/NO, got {$showColumnsRow['Null']}"),
			},
			$this->findColumnDefaultValue($table, $showColumnsRow['COLUMN_NAME'], $showColumnsRow['COLUMN_DEFAULT']),
			stripos($showColumnsRow['EXTRA'] ?? '', 'auto_increment') !== false,
		);
	}

	/** @throws DbReflectionException */
	private function findColumnDefaultValue(string $table, string $field, ?string $defaultValue): ?Expr
	{
		try {
			return $defaultValue !== null
				? $this->parser->parseSingleExpression($defaultValue)
				: null;
		} catch (ParserException $e) {
			throw new DbReflectionException(
				"Failed to parse default value of column `{$table}`.`{$field}`: [{$defaultValue}].",
				previous: $e,
			);
		}
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
