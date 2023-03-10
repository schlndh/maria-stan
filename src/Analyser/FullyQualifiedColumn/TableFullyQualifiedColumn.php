<?php

declare(strict_types=1);

namespace MariaStan\Analyser\FullyQualifiedColumn;

use MariaStan\Analyser\ColumnInfo;

final class TableFullyQualifiedColumn implements FullyQualifiedColumn
{
	public function __construct(public readonly ColumnInfo $columnInfo)
	{
	}

	public static function getColumnType(): FullyQualifiedColumnTypeEnum
	{
		return FullyQualifiedColumnTypeEnum::TABLE;
	}
}
