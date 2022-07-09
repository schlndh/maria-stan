<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class Table extends BaseNode implements TableReference
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $name,
		public readonly ?string $alias = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getTableReferenceType(): TableReferenceTypeEnum
	{
		return TableReferenceTypeEnum::TABLE;
	}
}
