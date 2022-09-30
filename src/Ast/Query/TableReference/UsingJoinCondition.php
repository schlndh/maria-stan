<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class UsingJoinCondition extends BaseNode
{
	/** @param non-empty-array<string> $columnNames */
	public function __construct(Position $startPosition, Position $endPosition, public readonly array $columnNames)
	{
		parent::__construct($startPosition, $endPosition);
	}
}
