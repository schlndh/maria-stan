<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi\data;

use MariaStan\DatabaseTestCaseHelper;
use PHPUnit\Framework\TestCase;

class MySQLiRuleValidDataTest extends TestCase
{
	public function testValid(): void
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();

		$stmt = $db->prepare('SELECT 1');
		$stmt->execute();
		$stmt->close();

		$stmt = $db->prepare('SELECT 1');
		$stmt->execute(null);
		$stmt->close();

		$stmt = $db->prepare('SELECT 1');
		$stmt->execute([]);
		$stmt->close();

		$stmt = $db->prepare('SELECT ?');
		$stmt->execute([1]);
		$stmt->close();

		// Make phpunit happy. I just care that it doesn't throw an exception and that phpstan doesn't report errors.
		$this->assertTrue(true);
	}
}
