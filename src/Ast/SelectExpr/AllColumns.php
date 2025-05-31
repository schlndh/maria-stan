<?php

declare(strict_types=1);

namespace MariaStan\Ast\SelectExpr;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\TableReference\TableName;
use MariaStan\Parser\Position;

final class AllColumns extends BaseNode implements SelectExpr
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly ?TableName $tableName = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getSelectExprType(): SelectExprTypeEnum
	{
		return SelectExprTypeEnum::ALL_COLUMNS;
	}
}
