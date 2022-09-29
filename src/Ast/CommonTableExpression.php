<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Ast\Query\SelectQuery\CombinedSelectQuery;
use MariaStan\Ast\Query\SelectQuery\SimpleSelectQuery;
use MariaStan\Parser\Position;

final class CommonTableExpression extends BaseNode
{
	/**
	 * @param array<string>|null $columnList
	 * @param array<string>|null $restrictCycleColumnList
	 */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $name,
		public readonly SimpleSelectQuery|CombinedSelectQuery $subquery,
		public readonly ?array $columnList = null,
		public readonly ?array $restrictCycleColumnList = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
