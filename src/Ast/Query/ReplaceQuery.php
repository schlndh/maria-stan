<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Query\InsertBody\InsertBody;
use MariaStan\Ast\Query\TableReference\TableName;
use MariaStan\Parser\Position;

final class ReplaceQuery extends BaseNode implements Query
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly TableName $tableName,
		public readonly InsertBody $insertBody,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::REPLACE;
	}
}
