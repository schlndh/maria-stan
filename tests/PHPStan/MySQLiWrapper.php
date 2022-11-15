<?php

declare(strict_types=1);

namespace MariaStan\PHPStan;

use MariaStan\Util\MysqliUtil;
use mysqli;

use function implode;

class MySQLiWrapper
{
	public function __construct(private readonly mysqli $db)
	{
	}

	/**
	 * This is a simple shortcut method for INSERT queries. Many database abstractions have similar shortcuts so this
	 * provides an example of how to implement an extension for them.
	 * See e.g. https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/data-retrieval-and-manipulation.html#insert
	 *
	 * @param non-empty-array<string, int|float|string|null> $data column => value
	 */
	public function insert(string $table, array $data): void
	{
		$tableQuoted = MysqliUtil::quoteIdentifier($table);
		$sql = "INSERT INTO {$tableQuoted} SET ";
		$params = [];
		$sets = [];

		foreach ($data as $col => $value) {
			$sets[] = MysqliUtil::quoteIdentifier($col) . ' = ?';
			$params[] = $value;
		}

		$sql .= implode(', ', $sets);
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$stmt->close();
	}
}
