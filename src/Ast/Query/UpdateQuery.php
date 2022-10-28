<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Assignment;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Parser\Position;

final class UpdateQuery extends BaseNode implements Query
{
	/** @param non-empty-array<Assignment> $assignments */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly TableReference $table,
		public readonly array $assignments,
		public readonly ?Expr $where = null,
		public readonly ?OrderBy $orderBy = null,
		public readonly ?Expr $limit = null,
		public readonly bool $ignoreErrors = false,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::UPDATE;
	}
}
