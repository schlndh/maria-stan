<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Parser\Position;

final class Subquery extends BaseNode implements Expr
{
	public function __construct(Position $startPosition, Position $endPosition, public readonly SelectQuery $query)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::SUBQUERY;
	}
}
