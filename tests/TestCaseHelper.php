<?php

declare(strict_types=1);

namespace MariaStan;

use MariaStan\Database\FunctionInfo\FunctionInfoRegistry;
use MariaStan\Database\FunctionInfo\FunctionInfoRegistryFactory;
use MariaStan\Parser\MariaDbParser;
use mysqli;
use mysqli_sql_exception;

use function is_string;
use function mysqli_report;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable.DisallowedSuperGlobalVariable

abstract class TestCaseHelper
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

		$mysqli = new mysqli(
			// see phpunit.xml
			self::getConfigValue($prefix, 'host'),
			self::getConfigValue($prefix, 'user'),
			self::getConfigValue($prefix, 'password'),
			port: (int) self::getConfigValue($prefix, 'port'),
		);
		$mysqli->set_opt(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
		$mysqli->set_charset('utf8mb4');
		mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);
		$dbName = self::getConfigValue($prefix, 'dbname');

		try {
			$mysqli->query('USE ' . $dbName);
		} catch (mysqli_sql_exception) {
			$mysqli->query('CREATE DATABASE ' . $dbName);
		}

		return self::$connections[$prefix] = $mysqli;
	}

	public static function createFunctionInfoRegistry(): FunctionInfoRegistry
	{
		return (new FunctionInfoRegistryFactory())->create();
	}

	public static function createParser(): MariaDbParser
	{
		return new MariaDbParser(self::createFunctionInfoRegistry());
	}

	private static function getConfigValue(string $prefix, string $property): string
	{
		$configKey = $prefix . $property;

		if (! isset($_ENV[$configKey])) {
			throw new \RuntimeException("Missing DB config {$configKey}!");
		}

		$value = $_ENV[$configKey];

		if (! is_string($value)) {
			throw new \RuntimeException("Wrong config value for {$configKey} - expected string!");
		}

		return $value;
	}
}
