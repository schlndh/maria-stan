<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Expr;

final class Join extends BaseNode implements TableReference
{
	public function __construct(
		public readonly JoinTypeEnum $joinType,
		public readonly TableReference $leftTable,
		public readonly TableReference $rightTable,
		public readonly Expr|UsingJoinCondition|null $joinCondition,
	) {
		parent::__construct(
			$this->leftTable->getStartPosition(),
			$this->joinCondition?->getEndPosition() ?? $this->rightTable->getEndPosition(),
		);
	}

	public static function getTableReferenceType(): TableReferenceTypeEnum
	{
		return TableReferenceTypeEnum::JOIN;
	}
}
