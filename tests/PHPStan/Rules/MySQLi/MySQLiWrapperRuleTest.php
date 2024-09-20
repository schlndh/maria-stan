<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Rules\MySQLi\data\MySQLiWrapperRuleInvalidDataTest;
use MariaStan\PHPStan\Rules\MySQLi\data\MySQLiWrapperRuleValidDataTest;
use mysqli;
use PHPStan\Rules\Rule;

/** @extends BaseRuleTestCase<MySQLiWrapperRule> */
class MySQLiWrapperRuleTest extends BaseRuleTestCase
{
	protected function getRule(): Rule
	{
		return self::getContainer()->getByType(MySQLiWrapperRule::class);
	}

	public static function getCurrentFile(): string
	{
		return __FILE__;
	}

	public function initData(mysqli $db): void
	{
		MySQLiWrapperRuleInvalidDataTest::initData($db);
		MySQLiWrapperRuleValidDataTest::initData($db);
	}
}
