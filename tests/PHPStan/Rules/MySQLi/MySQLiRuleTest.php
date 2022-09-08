<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\MySQLi;

use MariaStan\PHPStan\Rules\MariaStanRuleTestCase;
use MariaStan\PHPStan\Rules\MySQLi\data\MySQLiRuleInvalidDataTest;
use mysqli;
use PHPStan\Rules\Rule;

use function assert;

/** @extends MariaStanRuleTestCase<MySQLiRule> */
class MySQLiRuleTest extends MariaStanRuleTestCase
{
	protected function getRule(): Rule
	{
		return self::getContainer()->getByType(MySQLiRule::class);
	}

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestRuleData(): iterable
	{
		$mysqli = self::getContainer()->getService('mariaStanDb');
		assert($mysqli instanceof mysqli);
		MySQLiRuleInvalidDataTest::initData($mysqli);

		yield 'invalid' => $this->gatherAssertErrors(__DIR__ . '/data/MySQLiRuleInvalidDataTest.php');
	}

	/**
	 * @dataProvider provideTestRuleData()
	 * @param array<array{string, int}> $errors [[error, line number]]
	 */
	public function testRule(string $file, array $errors): void
	{
		$this->analyse([$file], $errors);
	}

	/** @return array<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/../../../../extension.mysqli.neon',
			__DIR__ . '/../../test.neon',
		];
	}
}
