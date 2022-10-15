<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\InsertBody;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Column;
use MariaStan\Ast\Query\SelectQuery\SelectQuery;
use MariaStan\Parser\Position;

final class SelectInsertBody extends BaseNode implements InsertBody
{
	/** @param non-empty-array<Column>|null $columnList */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly ?array $columnList,
		public readonly SelectQuery $selectQuery,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getInsertBodyType(): InsertBodyTypeEnum
	{
		return InsertBodyTypeEnum::SELECT;
	}
}
