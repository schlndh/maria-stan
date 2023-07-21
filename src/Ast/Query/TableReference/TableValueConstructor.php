<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class TableValueConstructor extends BaseNode implements TableReference
{
	/** @param non-empty-array<non-empty-array<Expr>> $values */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly array $values,
		public readonly ?string $alias,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getTableReferenceType(): TableReferenceTypeEnum
	{
		return TableReferenceTypeEnum::TABLE_VALUE_CONSTRUCTOR;
	}
}
