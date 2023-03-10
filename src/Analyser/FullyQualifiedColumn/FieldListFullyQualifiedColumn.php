<?php

declare(strict_types=1);

namespace MariaStan\Analyser\FullyQualifiedColumn;

use MariaStan\Analyser\QueryResultField;

class FieldListFullyQualifiedColumn implements FullyQualifiedColumn
{
	public function __construct(public readonly QueryResultField $field)
	{
	}

	public static function getColumnType(): FullyQualifiedColumnTypeEnum
	{
		return FullyQualifiedColumnTypeEnum::FIELD_LIST;
	}
}
