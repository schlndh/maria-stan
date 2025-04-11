<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use function assert;
use function max;
use function mb_substr;
use function min;
use function strlen;
use function strrpos;
use function substr;
use function substr_count;

// Based on
// https://github.com/nette/latte/blob/13c81eeaafdbce06e6cb57cbd0c1190622d8a1bd/src/Latte/Compiler/Position.php
final class Position
{
	public function __construct(
		public readonly int $line,
		public readonly int $column,
		public readonly int $offset = 0,
	) {
	}

	public function advance(string $str): self
	{
		$lines = substr_count($str, "\n");

		if ($lines > 0) {
			$newLinePos = strrpos($str, "\n");
			// implied by if ($lines)
			assert($newLinePos !== false);

			return new self(
				$this->line + $lines,
				strlen($str) - $newLinePos,
				$this->offset + strlen($str),
			);
		}

		return new self(
			$this->line,
			$this->column + strlen($str),
			$this->offset + strlen($str),
		);
	}

	public function findSubstringStartingWithPosition(string $str, ?int $length = null): string
	{
		// TODO: this is not UTF-8 safe.
		return substr($str, $this->offset, $length);
	}

	public function findSubstringEndingWithPosition(string $str, ?int $length = null): string
	{
		// TODO: this is not UTF-8 safe.
		$start = $length !== null
			? max(0, $this->offset - $length)
			: 0;
		$length ??= $this->offset;
		$length = min($length, $this->offset - $start);

		return substr($str, $start, $length);
	}

	public function findSubstringToEndPosition(string $str, self $endPosition, ?int $maxLength = null): string
	{
		$length = $endPosition->offset - $this->offset;
		$result = substr($str, $this->offset, $length);

		return $maxLength !== null
			? mb_substr($result, 0, $maxLength)
			: $result;
	}
}

/**
Copyright (c) 2004, 2014 David Grudl (https://davidgrudl.com)
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice,
this list of conditions and the following disclaimer.

 * Redistributions in binary form must reproduce the above copyright notice,
this list of conditions and the following disclaimer in the documentation
and/or other materials provided with the distribution.

 * Neither the name of "Nette Framework" nor the names of its contributors
may be used to endorse or promote products derived from this software
without specific prior written permission.

This software is provided by the copyright holders and contributors "as is" and
any express or implied warranties, including, but not limited to, the implied
warranties of merchantability and fitness for a particular purpose are
disclaimed. In no event shall the copyright owner or contributors be liable for
any direct, indirect, incidental, special, exemplary, or consequential damages
(including, but not limited to, procurement of substitute goods or services;
loss of use, data, or profits; or business interruption) however caused and on
any theory of liability, whether in contract, strict liability, or tort
(including negligence or otherwise) arising in any way out of the use of this
software, even if advised of the possibility of such damage.
 */
