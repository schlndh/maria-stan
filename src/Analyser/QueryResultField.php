<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

final class QueryResultField
{
	public function __construct(public readonly string $name, public readonly ExprTypeResult $exprType)
	{
	}

	public function getRenamed(string $newName): self
	{
		return new self($newName, $this->exprType);
	}
}
