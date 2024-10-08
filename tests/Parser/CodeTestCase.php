<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use RuntimeException;

use function array_merge;
use function count;
use function ltrim;
use function MariaStan\filesInDir;
use function mb_substr;
use function realpath;
use function str_replace;
use function trim;

trait CodeTestCase
{
	/**
	 * @param int<1, max> $chunksPerTest
	 * @return iterable<string, array<mixed>> test name => args
	 */
	protected static function getTests(string $directory, string $fileExtension, int $chunksPerTest = 2): iterable
	{
		$parser = new CodeTestParser();
		$allTests = [];
		$directory = realpath($directory) ?: throw new RuntimeException('Invalid directory');

		foreach (filesInDir($directory, $fileExtension) as $fileName => $fileContents) {
			$fileName = realpath($fileName) ?: throw new RuntimeException('Invalid file');
			[$name, $tests] = $parser->parseTest($fileContents, $chunksPerTest);

			// first part is the name
			$name .= ' (' . $fileName . ')';
			$shortName = ltrim(str_replace($directory, '', $fileName), '/\\');

			// multiple sections possible with always two forming a pair
			foreach ($tests as $i => [, $parts]) {
				$dataSetName = $shortName . (count($parts) > 1
						? '#' . $i
						: '');
				$dataSetName .= ' ' . trim(mb_substr($parts[0], 0, 100));
				$allTests[$dataSetName] = array_merge([$name], $parts);
			}
		}

		return $allTests;
	}
}

/**
 * Based on
 * https://github.com/nikic/PHP-Parser/blob/5aae65e627f8f3cdf6b109dc6a2bddbd92e0867a/test/PhpParser/CodeTestAbstract.php
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
