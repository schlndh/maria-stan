<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Parser\Exception\LexerException;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function MariaStan\canonicalize;
use function strlen;

// If valid output changes, re-run updateTests.php
class MariaDbLexerTest extends TestCase
{
	use CodeTestCase;

	/** @dataProvider provideTestParse */
	public function testParse(string $name, string $code, string $expected): void
	{
		$parser = $this->createLexer();
		[$tokens, $output] = $this->getLexerOutput($parser, $code);

		foreach ($tokens as $token) {
			$this->assertNotNull($token->position);
			$this->assertSame(
				$token->content,
				$token->position->findSubstringStartingWithPosition($code, strlen($token->content)),
			);
		}

		$this->assertSame($expected, $output, $name);
	}

	public function createLexer(): MariaDbLexer
	{
		return new MariaDbLexer();
	}

	/**
	 * @return array{array<Token>|LexerException, string} [result, output]
	 *
	 * Must be public for updateTests.php
	 */
	public function getLexerOutput(MariaDbLexer $parser, string $code): array
	{
		try {
			$tokens = $parser->tokenize($code);

			return [$tokens, canonicalize(implode("\n", array_map($this->dumpToken(...), $tokens)))];
		} catch (LexerException $e) {
			return [$e, canonicalize($e::class . "\n{$e->getMessage()}")];
		}
	}

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestParse(): iterable
	{
		return $this->getTests(__DIR__ . '/../code/Parser/MariaDbLexer', 'test');
	}

	private function dumpToken(Token $token): string
	{
		return "{$token->type->value}: [{$token->content}]";
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
