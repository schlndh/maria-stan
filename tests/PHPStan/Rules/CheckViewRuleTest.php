<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules;

use MariaStan\PHPStan\Rules\data\CheckViewRuleInvalidDataTest;
use MariaStan\PHPStan\Rules\data\CheckViewRuleValidDataTest;
use mysqli;
use PHPStan\Rules\Rule;

/** @extends BaseRuleTestCase<CheckViewRule> */
class CheckViewRuleTest extends BaseRuleTestCase
{
	protected function getRule(): Rule
	{
		return self::getContainer()->getByType(CheckViewRule::class);
	}

	public static function getCurrentFile(): string
	{
		return __FILE__;
	}

	public function initData(mysqli $db): void
	{
		CheckViewRuleValidDataTest::initData($db);
		CheckViewRuleInvalidDataTest::initData($db);
	}
}
