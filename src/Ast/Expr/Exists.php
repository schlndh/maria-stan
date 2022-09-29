<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\SelectQuery\SelectQuery;
use MariaStan\Parser\Position;

final class Exists extends BaseNode implements Expr
{
	public function __construct(Position $startPosition, Position $endPosition, public readonly SelectQuery $subquery)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getExprType(): ExprTypeEnum
	{
		return ExprTypeEnum::EXISTS;
	}
}
