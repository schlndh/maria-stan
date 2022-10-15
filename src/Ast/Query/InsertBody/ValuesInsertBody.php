<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\InsertBody;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Column;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

final class ValuesInsertBody extends BaseNode implements InsertBody
{
	/**
	 * @param non-empty-array<Column>|null $columnList
	 * @param non-empty-array<non-empty-array<Expr>> $values
	 */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly ?array $columnList,
		public readonly array $values,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getInsertBodyType(): InsertBodyTypeEnum
	{
		return InsertBodyTypeEnum::VALUES;
	}
}
