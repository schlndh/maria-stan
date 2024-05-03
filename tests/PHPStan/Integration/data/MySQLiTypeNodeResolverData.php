<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Integration\data;

use MariaStan\PHPStan\Type\MySQLi\MySQLiTableAssocRowType;
use MariaStan\PHPStan\Type\MySQLi\MySQLiTableAssocRowType as RowType;

use function is_array;
use function rand;
use function var_dump;

use const MYSQLI_ASSOC;

class MySQLiTypeNodeResolverData
{
	public function __construct(private readonly \mysqli $db)
	{
	}

	public function check(): void
	{
		$row = $this->returnSingleMysqliTestRow();
		$this->acceptMysqliTestRow($row);
		$this->acceptMysqliTestRow(['id' => 5, 'name' => 'aaa', 'price' => '11.11']);
		$this->acceptMysqliTestRow($this->returnSingleMysqliTestRowAlias());
		var_dump($this->returnSingleRowFromSeveralTables()['id']);
	}

	/** @phpstan-return MySQLiTableAssocRowType<'mysqli_type_node_test'> */
	public function returnSingleMysqliTestRow(): array
	{
		$result = $this->db->query('SELECT * FROM mysqli_type_node_test');
		$row = $result->fetch_array(MYSQLI_ASSOC);

		if (! is_array($row)) {
			throw new \Exception();
		}

		return $row;
	}

	/** @phpstan-return array<MySQLiTableAssocRowType<'mysqli_type_node_test'>> */
	public function returnAllMysqliTestRow(): array
	{
		$result = $this->db->query('SELECT * FROM mysqli_type_node_test');

		return $result->fetch_all(MYSQLI_ASSOC);
	}

	/** @phpstan-return MySQLiTableAssocRowType<'mysqli_type_node_test'> */
	public function mockMysqliTestRow(): array
	{
		$result = $this->db->query('SELECT 1 id, "aa" name, 11.11 price');
		$row = $result->fetch_array(MYSQLI_ASSOC);

		if (! is_array($row)) {
			throw new \Exception();
		}

		return $row;
	}

	/** @phpstan-return MySQLiTableAssocRowType<'mysqli_type_node_test'> */
	public function wrongReturn(): array
	{
		$result = $this->db->query('SELECT 1');
		$row = $result->fetch_array(MYSQLI_ASSOC);

		if (! is_array($row)) {
			throw new \Exception();
		}

		return $row;
	}

	/** @phpstan-return MySQLiTableAssocRowType<'mysqli_type_node_test'|'mysqli_type_node_test_2'> */
	public function returnSingleRowFromSeveralTables(): array
	{
		$result = rand()
			? $this->db->query('SELECT * FROM mysqli_type_node_test')
			: $this->db->query('SELECT * FROM mysqli_type_node_test_2');
		$row = $result->fetch_array(MYSQLI_ASSOC);

		if (! is_array($row)) {
			throw new \Exception();
		}

		return $row;
	}

	/** @phpstan-return RowType<'mysqli_type_node_test'> */
	public function returnSingleMysqliTestRowAlias(): array
	{
		$result = $this->db->query('SELECT * FROM mysqli_type_node_test');
		$row = $result->fetch_array(MYSQLI_ASSOC);

		if (! is_array($row)) {
			throw new \Exception();
		}

		return $row;
	}

	/** @phpstan-param MySQLiTableAssocRowType<'mysqli_type_node_test'> $row */
	public function acceptMysqliTestRow(array $row): int
	{
		return $row['id'];
	}
}
