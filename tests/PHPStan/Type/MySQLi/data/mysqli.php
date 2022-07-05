<?php

declare(strict_types=1);

use function PHPStan\Testing\assertType;

function mysqli_test(mysqli $db): void
{
	$rows = $db->query('
		SELECT * FROM mysqli_test
	')->fetch_all(MYSQLI_ASSOC);

	foreach ($rows as $row) {
		assertType('int', $row['id']);
		assertType('string|null', $row['name']);
		assertType('*ERROR*', $row['doesnt_exist']);

		break;
	}
}
