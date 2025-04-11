<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\Query;
use MariaStan\Parser\Exception\ParserException;
use MariaStan\TestCaseHelper;
use MariaStan\Util\MariaDbErrorCodes;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;
use UnitEnum;

use function array_filter;
use function array_map;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function MariaStan\canonicalize;
use function print_r;
use function str_repeat;
use function str_replace;
use function substr;

// If valid output changes, re-run updateTests.php
class MariaDbParserTest extends TestCase
{
	use CodeTestCase;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$initTable = static function (string $suffix): void {
			$db = TestCaseHelper::getDefaultSharedConnection();

			$tableName = 'parser_test' . $suffix;
			$db->query("
				CREATE OR REPLACE TABLE {$tableName} (
					id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(255) NULL
				);		
			");
			$db->query("INSERT INTO {$tableName} (id, name) VALUES (1, 'aa'), (2, NULL)");
		};

		foreach (['', '_join_a', '_join_b', '_join_c', '_join_d', '_truncate'] as $suffix) {
			$initTable($suffix);
		}

		$db = TestCaseHelper::getDefaultSharedConnection();
		$db->query("
				CREATE OR REPLACE TABLE parser_test_index (
					id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(255) NULL,
					priority INT NOT NULL,
					INDEX (id),
					INDEX (name),
					INDEX (priority, name, id)
				);
			");
		$db->query("INSERT INTO parser_test_index (id, name, priority) VALUES (1, 'aa', 10), (2, NULL, 9)");
	}

	/** @dataProvider provideTestParseValidData */
	public function testParseValid(string $name, string $code, string $expected): void
	{
		// Make sure the query doesn't throw an exception
		$db = TestCaseHelper::getDefaultSharedConnection();
		[$dbException] = $this->getDbOutput($db, $code);

		if ($dbException !== null) {
			throw $dbException;
		}

		$parser = $this->createParser();
		[$query, $output] = $this->getParseOutput($parser, $code);

		if ($query instanceof \Throwable) {
			throw $query;
		}

		// ignore ( and whitespace at the beginning
		$beginning = substr($code, 0, $query->getStartPosition()->offset);
		$this->assertMatchesRegularExpression('/[\s(]*/', $beginning);
		$end = $query->getEndPosition()->findSubstringStartingWithPosition($code);
		// ignore ), whitespace and ; at the end
		$this->assertMatchesRegularExpression('/[\s)]*[\s;]*/', $end);

		$this->assertSame($expected, $output, $name);
	}

	/** @dataProvider provideTestParseInvalidData */
	public function testParseInvalid(string $name, string $code, string $expectedOutput): void
	{
		$parser = $this->createParser();
		$db = TestCaseHelper::getDefaultSharedConnection();
		[$e, $parserOutput] = $this->getParseOutput($parser, $code);
		[$dbException, $dbOutput] = $this->getDbOutput($db, $code);

		if ($dbException === null) {
			$this->fail("Expected query to fail, but it didn't.");
		}

		if (
			! in_array(
				$dbException->getCode(),
				[
					MariaDbErrorCodes::ER_PARSE_ERROR,
					// The AST doesn't even allow this, so we have to handle it in the parser.
					MariaDbErrorCodes::ER_BAD_COMBINATION_OF_WINDOW_FRAME_BOUND_SPECS,
					// (SELECT) UNION (SELECT) FOR UPDATE
					// 1221: Incorrect usage of lock options and SELECT in brackets
					MariaDbErrorCodes::ER_WRONG_USAGE,
					// Allow handling functions that may not have whitespace before parenthesis on parser level.
					// E.g. POSITION ('a' IN 'b')
					MariaDbErrorCodes::ER_FUNC_INEXISTENT_NAME_COLLISION,
					// For some reason MariaDB returns this error instead of a parse error when encountering
					// unsupported CAST(x AS INTERVAL ...).
					MariaDbErrorCodes::ER_UNKNOWN_DATA_TYPE,
					// Some of these functions have custom syntax and in those cases it can be enforced on AST level.
					MariaDbErrorCodes::ER_WRONG_PARAMCOUNT_TO_NATIVE_FCT,
					// E.g. 'x' COLLATE CONCAT(...) returns this error instead of parser error.
					MariaDbErrorCodes::ER_UNKNOWN_COLLATION,
					// RANGE-type frame requires ORDER BY clause with single sort key
					MariaDbErrorCodes::ER_RANGE_FRAME_NEEDS_SIMPLE_ORDERBY,
				],
				true,
			)
		) {
			$this->fail("Expected DB to return parse error. Got: '{$dbOutput}'. This should be tested somewhere else.");
		}

		$this->assertSame($expectedOutput, $parserOutput . "\n#####\n" . $dbOutput, $name);
		$this->assertInstanceOf(ParserException::class, $e);
	}

	public function createParser(): MariaDbParser
	{
		return TestCaseHelper::createParser();
	}

	/**
	 * @return array{Query|ParserException, string} [result, output]
	 *
	 * Must be public for updateTests.php
	 */
	public function getParseOutput(MariaDbParser $parser, string $code): array
	{
		try {
			$query = $parser->parseSingleQuery($code);

			return [$query, canonicalize($this->printDumpedData($this->dumpNodeData($query)))];
		} catch (ParserException $e) {
			return [$e, canonicalize($e::class . "\n{$e->getMessage()}")];
		}
	}

	/**
	 * @return array{?mysqli_sql_exception, string} [result, output]
	 *
	 * Must be public for updateTests.php
	 */
	public function getDbOutput(mysqli $db, string $code): array
	{
		$lexer = new MariaDbLexer();
		$tokens = $lexer->tokenize($code);
		$params = [];

		foreach ($tokens as $token) {
			if ($token->type === TokenTypeEnum::SINGLE_CHAR && $token->content === '?') {
				$params[] = 1;
			}
		}

		$db->begin_transaction();

		try {
			if (count($params) === 0) {
				$result = $db->query($code);

				if ($result instanceof mysqli_result) {
					$result->close();
				}
			} else {
				try {
					$db->prepare($code)->execute($params);
				} catch (mysqli_sql_exception $e) {
					// This happens with GROUP_CONCAT(id LIMIT ?), but not with LIMIT ?. So it seems like a bug.
					if ($e->getCode() !== MariaDbErrorCodes::ER_INVALID_VALUE_TO_LIMIT) {
						throw $e;
					}

					$stmt = $db->prepare($code);
					$stmt->bind_param(str_repeat('i', count($params)), ...$params);

					try {
						$stmt->execute();
					} finally {
						$stmt->close();
					}
				}
			}

			return [null, 'Query ran without exception'];
		} catch (mysqli_sql_exception $e) {
			// TODO: Is it a good idea to put the error message there?
			return [$e, canonicalize($e->getCode() . ": {$e->getMessage()}")];
		} finally {
			$db->rollback();
		}
	}

	/** @return iterable<string, array<mixed>> name => args */
	public static function provideTestParseValidData(): iterable
	{
		return self::getTests(__DIR__ . '/../code/Parser/MariaDbParser/valid', 'test');
	}

	/** @return iterable<string, array<mixed>> name => args */
	public static function provideTestParseInvalidData(): iterable
	{
		return self::getTests(__DIR__ . '/../code/Parser/MariaDbParser/invalid', 'test', 2);
	}

	private function dumpNodeData(mixed $data): mixed
	{
		if ($data instanceof Node) {
			$reflectionClass = new \ReflectionClass($data);
			$properties = $reflectionClass->getProperties(~\ReflectionProperty::IS_STATIC);
			$result = [
				'__CLASS__' => $data::class,
			];

			foreach ($properties as $property) {
				// skip positions
				if ($property->getDeclaringClass()->getName() === BaseNode::class) {
					continue;
				}

				$val = $property->getValue($data);
				$val = $this->dumpNodeData($val);
				$result[$property->name] = $val;
			}

			return array_filter($result, static fn (mixed $value) => $value !== null);
		}

		if (is_array($data)) {
			return array_filter(
				array_map($this->dumpNodeData(...), $data),
				static fn (mixed $value) => $value !== null,
			);
		}

		return $data;
	}

	private function printDumpedData(mixed $data): string
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

			foreach ($data as $key => $value) {
				$printedValue = $this->printDumpedData($value);
				$printedValue = str_replace("\n", "\n\t\t", $printedValue);
				$result .= "\t[{$key}] => {$printedValue}\n";
			}

			return $result . ')';
		} elseif (is_bool($data)) {
			$result = $data
				? 'true'
				: 'false';
		} elseif ($data instanceof UnitEnum) {
			$result = $data::class . '::' . $data->name;
		} else {
			$result = print_r($data, true);
		}

		return $result;
	}
}

/**
 * Based on
 * https://github.com/nikic/PHP-Parser/blob/5aae65e627f8f3cdf6b109dc6a2bddbd92e0867a/test/PhpParser/CodeParsingTest.php
 *
 * Original license:
 * BSD 3-Clause License
 *
 * Copyright (c) 2011, Nikita Popov
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 * list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
