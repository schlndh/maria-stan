<?php

declare(strict_types=1);

namespace MariaStan;

use function array_map;
use function assert;
use function closedir;
use function explode;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_dir;
use function opendir;
use function preg_quote;
use function readdir;
use function realpath;
use function rmdir;
use function rtrim;
use function str_replace;
use function unlink;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * The code below is based on
 * https://github.com/nikic/PHP-Parser/blob/5aae65e627f8f3cdf6b109dc6a2bddbd92e0867a/test/bootstrap.php
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

function canonicalize(string $str): string
{
	// normalize EOL style
	$str = str_replace("\r\n", "\n", $str);

	// trim newlines at end
	$str = rtrim($str, "\n");

	// remove trailing whitespace on all lines
	$lines = explode("\n", $str);
	$lines = array_map(static fn ($line) => rtrim($line, " \t"), $lines);

	return implode("\n", $lines);
}

/** @return iterable<string> file name */
function fileNamesInDir(string $directory, string $fileExtension): iterable
{
	$directory = realpath($directory);

	if (! $directory) {
		throw new \RuntimeException("{$directory} doesn't exist");
	}

	$it = new \RecursiveDirectoryIterator($directory);
	$it = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::LEAVES_ONLY);
	$it = new \RegexIterator($it, '(\.' . preg_quote($fileExtension) . '$)');

	foreach ($it as $file) {
		assert($file instanceof \SplFileInfo);

		yield $file->getPathname();
	}
}

/** @return iterable<string, string> file name => contents */
function filesInDir(string $directory, string $fileExtension): iterable
{
	foreach (fileNamesInDir($directory, $fileExtension) as $fileName) {
		$contents = file_get_contents($fileName);

		if ($contents === false) {
			throw new \RuntimeException("Failed to read {$fileName}.");
		}

		yield $fileName => $contents;
	}
}

// https://www.php.net/manual/en/function.rmdir.php#119949
function rrmdir(string $src): void
{
	if (! file_exists($src)) {
		return;
	}

	$dir = opendir($src);

	if ($dir === false) {
		return;
	}

	while (($file = readdir($dir)) !== false) {
		if (($file === '.') || ($file === '..')) {
			continue;
		}

		$full = $src . '/' . $file;

		if (is_dir($full)) {
			rrmdir($full);
		} else {
			unlink($full);
		}
	}

	closedir($dir);
	rmdir($src);
}
