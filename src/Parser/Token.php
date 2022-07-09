<?php

declare(strict_types=1);

namespace MariaStan\Parser;

class Token
{
	public function __construct(
		public readonly TokenTypeEnum $type,
		public readonly string $content,
		public readonly Position $position,
	) {
	}

	public function getEndPosition(): Position
	{
		return $this->position->advance($this->content);
	}
}
