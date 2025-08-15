<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Assignment;
use MariaStan\Ast\Query\InsertBody\InsertBody;
use MariaStan\Ast\Query\TableReference\TableName;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\Parser\Position;

final class InsertQuery extends BaseNode implements Query
{
	/**
	 * @param non-empty-array<Assignment>|null $onDuplicateKeyUpdate
	 * @param list<SelectExpr> $returning
	 */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly TableName $tableName,
		public readonly InsertBody $insertBody,
		public readonly ?array $onDuplicateKeyUpdate = null,
		public readonly bool $ignoreErrors = false,
		public readonly array $returning = [],
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::INSERT;
	}
}
