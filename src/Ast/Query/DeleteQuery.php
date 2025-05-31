<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Exception\InvalidAstException;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Ast\OrderBy;
use MariaStan\Ast\Query\TableReference\TableName;
use MariaStan\Ast\Query\TableReference\TableReference;
use MariaStan\Parser\Position;

use function count;

final class DeleteQuery extends BaseNode implements Query
{
	/** @param non-empty-array<TableName> $tablesToDelete */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly array $tablesToDelete,
		public readonly TableReference $table,
		public readonly ?Expr $where = null,
		public readonly ?OrderBy $orderBy = null,
		public readonly ?Expr $limit = null,
		public readonly bool $ignoreErrors = false,
	) {
		parent::__construct($startPosition, $endPosition);

		if (count($this->tablesToDelete) > 1 && $this->orderBy !== null) {
			throw new InvalidAstException('Multi-table DELETE cannot have ORDER BY clause');
		}

		if (count($this->tablesToDelete) > 1 && $this->limit !== null) {
			throw new InvalidAstException('Multi-table DELETE cannot have LIMIT clause');
		}
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::DELETE;
	}
}
