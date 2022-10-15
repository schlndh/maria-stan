<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class ColumnDefault extends BaseNode implements Expr
{
	public function __construct(Position $startPosition, Position $endPosition)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::COLUMN_DEFAULT;
	}
}
