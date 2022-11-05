<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Analyser\AnalyserErrorMessageBuilder;
use MariaStan\Ast\Expr\Expr;
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

use function array_combine;
use function array_map;
use function count;
use function explode;
use function preg_match;
use function stripos;
use function trim;

class InformationSchemaParser
{
	public function __construct(private readonly MariaDbParser $parser)
	{
	}

	/**
	 * @param array<array<string, scalar|null>> $tableCols [[column => value]]
	 * @throws DbReflectionException
	 */
	public function parseTableSchema(string $table, array $tableCols): Table
	{
		if (count($tableCols) === 0) {
			throw new TableDoesNotExistException(
				AnalyserErrorMessageBuilder::createTableDoesntExistErrorMessage($table),
				MariaDbErrorCodes::ER_NO_SUCH_TABLE,
			);
		}

		$isColumns = $this->parseInformationSchemaColumns($tableCols);
		$columns = array_map(
			fn (InformationSchemaColumn $c) => $this->createColumnSchema($table, $c),
			$isColumns,
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
	 * @param array<array<string, scalar|null>> $tableCols
	 * @return array<InformationSchemaColumn>
	 * @throws DbReflectionException
	 */
	private function parseInformationSchemaColumns(array $tableCols): array
	{
		$result = [];

		try {
			// Ignore phpstan issues because of possible type errors

			/** @phpstan-var array<string, string|null> $col */
			foreach ($tableCols as $col) {
				$result[] = new InformationSchemaColumn(
					$col['COLUMN_NAME'] ?? throw new UnexpectedValueException('COLUMN_NAME cannot be null'),
					$col['COLUMN_TYPE'] ?? throw new UnexpectedValueException('COLUMN_TYPE cannot be null'),
					match ($col['IS_NULLABLE'] ?? null) {
						'YES' => true,
						'NO' => false,
						default => throw new UnexpectedValueException("IS_NULLABLE must be YES/NO"),
					},
					$col['COLUMN_DEFAULT'],
					$col['EXTRA'] ?? throw new UnexpectedValueException('EXTRA cannot be null'),
				);
			}
		} catch (\TypeError $e) {
			throw new UnexpectedValueException('information_schema.COLUMNS parsing failed.', previous: $e);
		}

		return $result;
	}

	/** @throws DbReflectionException */
	private function createColumnSchema(string $table, InformationSchemaColumn $column): Column
	{
		return new Column(
			$column->name,
			$this->parseDbType($column->type),
			$column->isNullable,
			$this->findColumnDefaultValue($table, $column->name, $column->default),
			stripos($column->extra, 'auto_increment') !== false,
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
