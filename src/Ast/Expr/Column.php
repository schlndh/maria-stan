<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

class Column extends BaseNode implements Expr
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $name,
		public readonly ?string $tableName = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::COLUMN;
	}
}
