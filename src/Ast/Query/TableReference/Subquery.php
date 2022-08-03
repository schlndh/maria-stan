<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Parser\Position;

final class Subquery extends BaseNode implements TableReference
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly SelectQuery $query,
		public readonly string $alias,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getTableReferenceType(): TableReferenceTypeEnum
	{
		return TableReferenceTypeEnum::SUBQUERY;
	}
}
