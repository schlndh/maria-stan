<?php

declare(strict_types=1);

namespace MariaStan;

use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;

use function mysqli_report;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable.DisallowedSuperGlobalVariable

abstract class DatabaseTestCase extends TestCase
{
	/** @var array<string, mysqli> */
	private array $connections;

	protected function getDefaultSharedConnection(): mysqli
	{
		return $this->getSharedConnection('testdb_');
	}

	protected function getSharedConnection(string $prefix): mysqli
	{
		if (isset($this->connections[$prefix])) {
			return $this->connections[$prefix];
		}

		// see phpunit.xml
		$properties = [
			'host',
			'port',
			'user',
			'password',
			'dbname',
		];
		$values = [];

		foreach ($properties as $property) {
			$configKey = $prefix . $property;

			if (! isset($GLOBALS[$configKey])) {
				$this->fail("Missing DB config {$configKey}!");
			}

			$values[$property] = $GLOBALS[$configKey];
		}

		$mysqli = new mysqli($values['host'], $values['user'], $values['password'], port: (int) $values['port']);
		$mysqli->set_opt(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
		$mysqli->set_charset('utf8mb4');
		mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);

		try {
			$mysqli->query('USE ' . $values['dbname']);
		} catch (mysqli_sql_exception) {
			$mysqli->query('CREATE DATABASE ' . $values['dbname']);
		}

		return $this->connections[$prefix] = $mysqli;
	}
}
