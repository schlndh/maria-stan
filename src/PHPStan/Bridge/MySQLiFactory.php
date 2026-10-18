<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Bridge;

use mysqli;

use const MYSQLI_OPT_INT_AND_FLOAT_NATIVE;

class MySQLiFactory
{
	public function __construct(
		private readonly string $hostname,
		private readonly string $username,
		private readonly string $password,
		private readonly int $port,
	) {
	}

	public function create(): mysqli
	{
		$db = new mysqli($this->hostname, $this->username, $this->password, port: $this->port);
		$db->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
		$db->set_charset('utf8mb4');

		return $db;
	}
}
