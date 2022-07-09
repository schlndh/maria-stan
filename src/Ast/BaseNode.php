<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Parser\Position;

abstract class BaseNode implements Node
{
	public function __construct(protected readonly Position $startPosition, protected readonly Position $endPosition)
	{
	}

	public function getStartPosition(): Position
	{
		return $this->startPosition;
	}

	public function getEndPosition(): Position
	{
		return $this->endPosition;
	}
}
