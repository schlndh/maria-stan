<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Exception\InvalidAstException;
use MariaStan\Ast\Query\CombinedSelectQuery;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Parser\Position;

final class Subquery extends BaseNode implements TableReference
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		// TODO: fix usages
		public readonly SelectQuery|CombinedSelectQuery $query,
		public readonly ?string $alias,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getTableReferenceType(): TableReferenceTypeEnum
	{
		return TableReferenceTypeEnum::SUBQUERY;
	}

	public function getAliasOrThrow(): string
	{
		return $this->alias ?? throw new InvalidAstException('Subquery table has to have an alias');
	}
}
