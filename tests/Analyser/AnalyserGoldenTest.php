<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Analyser\PlaceholderTypeProvider\VarcharPlaceholderTypeProvider;
use MariaStan\DbReflection\InformationSchemaParser;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\Parser\CodeTestCase;
use MariaStan\Parser\MariaDbParser;
use MariaStan\TestCaseHelper;
use MariaStan\Util\MysqliUtil;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_is_list;
use function array_map;
use function count;
use function explode;
use function get_defined_constants;
use function get_object_vars;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_object;
use function json_decode;
use function MariaStan\canonicalize;
use function print_r;
use function str_replace;
use function str_starts_with;
use function substr;

use const JSON_THROW_ON_ERROR;

class AnalyserGoldenTest extends TestCase
{
	use CodeTestCase;

	public const SUBFIELD_SEPARATOR = "\n#######\n";

	/** @return iterable<string, array<mixed>> */
	public function provideTestData(): iterable
	{
		return self::getTests(__DIR__ . '/data', 'test');
	}

	/** @dataProvider provideTestData */
	public function test(string $name, string $code, string $expected): void
	{
		$parts = explode(self::SUBFIELD_SEPARATOR, $code);
		$query = $parts[0];
		$params = [];

		if (isset($parts[1])) {
			$rawParams = json_decode($parts[1], true, JSON_THROW_ON_ERROR);
			$this->assertIsArray($rawParams);

			foreach ($rawParams as $value) {
				if ($value !== null) {
					$this->assertIsScalar($value);
				}

				$params[] = $value;
			}
		}

		$output = self::getTestOutput($query, $params);
		$this->assertSame($expected, $output, $name);
	}

	/**
	 * @return array<string, array<string, array<string, array{query: string, params: array<scalar|null>}>>>
	 *     dir => set => test => data
	 */
	public static function getTestSets(): array
	{
		$result = [];
		$dirs = [
			'valid' => AnalyserTest::getValidTestSets(),
			'valid-nullability' => AnalyserTest::getValidNullabilityTestSets(),
			'invalid' => AnalyserTest::getInvalidTestSets(),
		];

		foreach ($dirs as $dirName => $dirData) {
			foreach ($dirData as $setName => $dataProvider) {
				foreach ($dataProvider() as $testName => $data) {
					self::assertIsString($data['query']);
					/** @var array<scalar|null> $params */
					$params = $data['params'] ?? [];
					$result[$dirName][$setName][$testName] = [
						'query' => $data['query'],
						'params' => $params,
					];
				}
			}
		}

		return $result;
	}

	/** @param array<scalar|null> $params */
	public static function getTestOutput(string $query, array $params = []): string
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$analyser = self::createAnalyser();
		$result = $analyser->analyzeQuery($query, new VarcharPlaceholderTypeProvider());
		$db->begin_transaction();

		try {
			$stmt = null;

			if (count($params) > 0) {
				$stmt = $db->prepare($query);
				$stmt->execute($params);
				$dbResult = $stmt->get_result();
			} else {
				$dbResult = $db->query($query);
			}

			if ($dbResult instanceof \mysqli_result) {
				$fields = $dbResult->fetch_fields();
				$dbResult->close();
			} else {
				$fields = [];
			}

			$stmt?->close();
			$dbData = self::printDumpedData(array_map(self::dumpMysqliField(...), $fields));
		} catch (\mysqli_sql_exception $e) {
			$dbData = "{$e->getCode()}: {$e->getMessage()}";
		} finally {
			$db->rollback();
		}

		return canonicalize(implode(
			self::SUBFIELD_SEPARATOR,
			[
				self::printDumpedData(self::dumpData($result)),
				$dbData,
			],
		));
	}

	private static function createAnalyser(): Analyser
	{
		$db = TestCaseHelper::getDefaultSharedConnection();
		$functionInfoRegistry = TestCaseHelper::createFunctionInfoRegistry();
		$parser = new MariaDbParser($functionInfoRegistry);
		$informationSchemaParser = new InformationSchemaParser($parser);
		$reflection = new MariaDbOnlineDbReflection($db, $informationSchemaParser);

		return new Analyser($parser, $reflection, $functionInfoRegistry);
	}

	/** @return array<string, mixed> */
	private static function dumpMysqliField(\stdClass $field): array
	{
		$result = [
			'__CLASS__' => $field::class,
		];

		foreach (get_object_vars($field) as $property => $val) {
			// Skip unused mess
			if (in_array($property, ['def', 'catalog', 'max_length'], true)) {
				continue;
			}

			// The DB name is dynamic based on environment.
			if ($property === 'db') {
				continue;
			}

			if ($property === 'flags') {
				self::assertIsInt($val);
				$val = MysqliUtil::getFlagNames($val);
			} elseif ($property === 'type') {
				self::assertIsInt($val);
				$val = self::dumpMysqliFieldType($val);
			} else {
				$val = self::dumpData($val);
			}

			$result[$property] = $val;
		}

		return array_filter($result, static fn (mixed $value) => $value !== null);
	}

	private static function dumpData(mixed $data): mixed
	{
		if ($data instanceof \UnitEnum) {
			return $data;
		}

		if (is_object($data)) {
			$reflectionClass = new \ReflectionClass($data);
			$properties = $reflectionClass->getProperties(~\ReflectionProperty::IS_STATIC);
			$result = [
				'__CLASS__' => $data::class,
			];

			foreach ($properties as $property) {
				$val = $property->getValue($data);
				$val = self::dumpData($val);
				$result[$property->name] = $val;
			}

			return array_filter($result, static fn (mixed $value) => $value !== null);
		}

		if (is_array($data)) {
			return array_filter(
				array_map(self::dumpData(...), $data),
				static fn (mixed $value) => $value !== null,
			);
		}

		return $data;
	}

	private static function printDumpedData(mixed $data): string
	{
		$result = '';

		if (is_array($data)) {
			if (isset($data['__CLASS__'])) {
				$class = $data['__CLASS__'];
				unset($data['__CLASS__']);
				self::assertIsString($class);
				$result .= $class . "\n(\n";
			} else {
				$result .= "Array\n(\n";
			}

			$isList = array_is_list($data);

			foreach ($data as $key => $value) {
				$printedValue = self::printDumpedData($value);
				$printedValue = str_replace("\n", "\n\t\t", $printedValue);
				$result .= $isList
					? "\t{$printedValue}\n"
					: "\t[{$key}] => {$printedValue}\n";
			}

			return $result . ')';
		} elseif (is_bool($data)) {
			$result = $data
				? 'true'
				: 'false';
		} elseif ($data instanceof \UnitEnum) {
			$result = $data::class . '::' . $data->name;
		} else {
			$result = print_r($data, true);
		}

		return $result;
	}

	private static function dumpMysqliFieldType(int $type): string
	{
		/** @var array<int, string>|null $map */
		static $map = null;

		if ($map === null) {
			$map = [];
			$constants = get_defined_constants(true);
			self::assertArrayHasKey('mysqli', $constants);
			self::assertIsArray($constants['mysqli']);

			foreach ($constants['mysqli'] as $name => $value) {
				self::assertIsString($name);

				if (! str_starts_with($name, 'MYSQLI_TYPE_')) {
					continue;
				}

				self::assertIsInt($value);
				$map[$value] = substr($name, 12);
			}
		}

		return $map[$type] ?? (string) $type;
	}
}
