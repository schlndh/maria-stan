<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\SelectQuery;

use MariaStan\Ast\Exception\InvalidAstException;
use MariaStan\Ast\Query\TableReference\TableValueConstructor;
use MariaStan\Parser\Position;

class TableValueConstructorSelectQuery extends BaseSelectQuery
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly TableValueConstructor $tableValueConstructor,
	) {
		parent::__construct($startPosition, $endPosition);

		if ($this->tableValueConstructor->alias !== null) {
			throw new InvalidAstException('Table value constructor as a query cannot have alias.');
		}
	}

	public static function getSelectQueryType(): SelectQueryTypeEnum
	{
		return SelectQueryTypeEnum::TABLE_VALUE_CONSTRUCTOR;
	}
}
