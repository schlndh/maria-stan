<?php

declare(strict_types=1);

namespace MariaStan\Parser;

class Token
{
	public function __construct(public readonly TokenTypeEnum $type, public readonly string $content)
	{
	}
}
