<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\InsertBody\InsertBody;
use MariaStan\Parser\Position;

final class InsertQuery extends BaseNode implements Query
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $tableName,
		public readonly InsertBody $insertBody,
		public readonly bool $ignoreErrors = false,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::INSERT;
	}
}
