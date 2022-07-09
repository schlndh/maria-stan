<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Node;
use MariaStan\Ast\Query\Query;
use MariaStan\Parser\Exception\ParserException;

use function array_filter;
use function array_map;
use function is_array;
use function MariaStan\canonicalize;
use function print_r;
use function str_replace;
use function substr;
use function trim;

// If valid output changes, re-run updateTests.php
class MariaDbParserTest extends CodeTestCase
{
	/** @dataProvider provideTestParse */
	public function testParse(string $name, string $code, string $expected): void
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

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestParse(): iterable
	{
		return $this->getTests(__DIR__ . '/../code/Parser/MariaDbParser', 'test');
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

			return array_filter($result);
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
