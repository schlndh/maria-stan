<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Rules\MySQLi\data\MySQLiRuleInvalidDataTest;
use MariaStan\PHPStan\Rules\MySQLi\data\MySQLiRuleValidDataTest;
use mysqli;
use PHPStan\Rules\Rule;

/** @extends BaseRuleTestCase<MySQLiRule> */
class MySQLiRuleTest extends BaseRuleTestCase
{
	protected function getRule(): Rule
	{
		return self::getContainer()->getByType(MySQLiRule::class);
	}

	public static function getCurrentFile(): string
	{
		return __FILE__;
	}

	public function initData(mysqli $db): void
	{
		MySQLiRuleInvalidDataTest::initData($db);
		MySQLiRuleValidDataTest::initData($db);
	}
}
