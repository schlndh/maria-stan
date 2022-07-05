<?php

declare(strict_types=1);

namespace MariaStan;

use PHPUnit\Framework\TestCase;

use function assert;

class NativeAssertTest extends TestCase
{
	public function testThatNativeAssertionsWork(): void
	{
		$this->expectException(\AssertionError::class);
		// fool phpstan
		assert($this->returnFalse());
	}

	private function returnFalse(): bool
	{
		return false;
	}
}
