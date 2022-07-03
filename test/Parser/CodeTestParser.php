<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use function MariaStan\canonicalize;

class CodeTestParser
{
	/**
	 * @param string $code
	 * @param int $chunksPerTest
	 * @return array{string, array<array{?string, array<string>}>} [test name, [[mode, [inputs, ..., output]]]]
	 */
	public function parseTest(string $code, int $chunksPerTest): array
	{
		$code = canonicalize($code);

		// evaluate @@{expr}@@ expressions
		//$code = preg_replace_callback(
		//	'/@@\{(.*?)\}@@/',
		//	function ($matches) {
		//		return eval('return ' . $matches[1] . ';');
		//	},
		//	$code
		//);

		// parse sections
		$parts = preg_split("/\n-----(?:\n|$)/", $code);

		// first part is the name
		$name = array_shift($parts);

		// multiple sections possible with always two forming a pair
		$chunks = array_chunk($parts, $chunksPerTest);
		$tests = [];

		foreach ($chunks as $chunk) {
			$lastPart = array_pop($chunk);
			[$lastPart, $mode] = $this->extractMode($lastPart);
			$tests[] = [$mode, array_merge($chunk, [$lastPart])];
		}

		return [$name, $tests];
	}

	/**
	 * @param string $name
	 * @param array<array{string, array<string>}> $tests [[mode, [inputs, ..., output]]]
	 * @return string
	 */
	public function reconstructTest(string $name, array $tests): string
	{
		$result = $name;

		foreach ($tests as [$mode, $parts]) {
			$lastPart = array_pop($parts);

			foreach ($parts as $part) {
				$result .= "\n-----\n$part";
			}

			$result .= "\n-----\n";

			if (null !== $mode) {
				$result .= "!!$mode\n";
			}

			$result .= $lastPart;
		}

		return $result;
	}

	/**
	 * @param string $expected
	 * @return array{string, ?string} [test output, mode]
	 */
	private function extractMode(string $expected): array
	{
		$firstNewLine = strpos($expected, "\n");

		if (false === $firstNewLine) {
			$firstNewLine = strlen($expected);
		}

		$firstLine = substr($expected, 0, $firstNewLine);

		if (0 !== strpos($firstLine, '!!')) {
			return [$expected, null];
		}

		$expected = (string) substr($expected, $firstNewLine + 1);

		return [$expected, substr($firstLine, 2)];
	}
}

/**
 * Based on
 * https://github.com/nikic/PHP-Parser/blob/5aae65e627f8f3cdf6b109dc6a2bddbd92e0867a/test/PhpParser/CodeTestParser.php
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
