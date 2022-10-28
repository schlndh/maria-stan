<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Lock\NoWait;
use MariaStan\Ast\Lock\Wait;
use MariaStan\Parser\Position;

final class TruncateQuery extends BaseNode implements Query
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $tableName,
		public readonly Wait|NoWait|null $wait = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getQueryType(): QueryTypeEnum
	{
		return QueryTypeEnum::TRUNCATE;
	}
}
