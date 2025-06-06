<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules\data;

use mysqli;

use function MariaStan\PHPStan\checkAllViews;
use function MariaStan\PHPStan\checkView;

class CheckViewRuleInvalidDataTest
{
	public static function initData(mysqli $db): void
	{
		$tableName = 'check_view_invalid';
		self::doInitDb($db, $tableName);
	}

	private static function doInitDb(mysqli $db, string $prefix): void
	{
		// Hide $tableName from phpstan so that it doesn't analyze these queries
		$db->query("
			CREATE OR REPLACE TABLE {$prefix}_table (
				id INT NOT NULL,
				name VARCHAR(255) NULL
			);
		");
		$db->query("
			CREATE OR REPLACE VIEW {$prefix}_view AS
			SELECT * FROM {$prefix}_table
		");
		$db->query("ALTER TABLE {$prefix}_table DROP COLUMN name");
	}

	public function test(): void
	{
		checkView('check_view_invalid_view');
		checkAllViews();
		checkView('view_that_doesnt_exist');
		checkView('check_view_invalid_view', 'db_that_doesnt_exist');
	}
}
