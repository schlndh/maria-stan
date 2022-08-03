<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\Query;
use MariaStan\DatabaseTestCaseHelper;
use MariaStan\Parser\Exception\ParserException;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnitEnum;

use function array_filter;
use function array_map;
use function is_array;
use function is_bool;
use function MariaStan\canonicalize;
use function print_r;
use function str_replace;
use function substr;
use function trim;

// If valid output changes, re-run updateTests.php
class MariaDbParserTest extends TestCase
{
	use CodeTestCase;

	/** @dataProvider provideTestParseValidData */
	public function testParseValid(string $name, string $code, string $expected): void
	{
		$parser = $this->createParser();
		[$query, $output] = $this->getParseOutput($parser, $code);

		if (! $query instanceof \Throwable) {
			$this->assertSame(
				trim($code, " \t\n\r\0\x0B;"),
				substr($code, $query->getStartPosition()->offset, $query->getEndPosition()->offset),
			);
		}

		$this->assertSame($expected, $output, $name);
	}

	/** @dataProvider provideTestParseInvalidData */
	public function testParseInvalid(
		string $name,
		string $code,
		string $expectedParserOutput,
		string $expectedDbError,
	): void {
		$parser = $this->createParser();
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		[, $parserOutput] = $this->getParseOutput($parser, $code);
		[, $dbOutput] = $this->getDbOutput($db, $code);

		$this->assertSame($expectedDbError, $dbOutput, $name);
		$this->assertSame($expectedParserOutput, $parserOutput, $name);
	}

	public function createParser(): MariaDbParser
	{
		return new MariaDbParser();
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
	 * @return array{mysqli_sql_exception, string} [result, output]
	 *
	 * Must be public for updateTests.php
	 */
	public function getDbOutput(mysqli $db, string $code): array
	{
		try {
			$db->query($code);
		} catch (mysqli_sql_exception $e) {
			// TODO: Is it a good idea to put the error message there?
			return [$e, canonicalize($e->getCode() . ": {$e->getMessage()}")];
		}

		throw new RuntimeException("'{$code}' was supposed to throw an exception, but didn't.");
	}

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestParseValidData(): iterable
	{
		return $this->getTests(__DIR__ . '/../code/Parser/MariaDbParser/valid', 'test');
	}

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestParseInvalidData(): iterable
	{
		return $this->getTests(__DIR__ . '/../code/Parser/MariaDbParser/invalid', 'test', 3);
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
			return array_filter(array_map($this->dumpNodeData(...), $data));
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
