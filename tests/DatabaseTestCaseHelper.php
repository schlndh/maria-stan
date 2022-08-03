<?php

declare(strict_types=1);

namespace MariaStan;

use mysqli;
use mysqli_sql_exception;

use function mysqli_report;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable.DisallowedSuperGlobalVariable

abstract class DatabaseTestCaseHelper
{
	/** @var array<string, mysqli> */
	private static array $connections = [];

	public static function getDefaultSharedConnection(): mysqli
	{
		return self::getSharedConnection('testdb_');
	}

	public static function getSharedConnection(string $prefix): mysqli
	{
		if (isset(self::$connections[$prefix])) {
			return self::$connections[$prefix];
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
				throw new \RuntimeException("Missing DB config {$configKey}!");
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

		return self::$connections[$prefix] = $mysqli;
	}
}
