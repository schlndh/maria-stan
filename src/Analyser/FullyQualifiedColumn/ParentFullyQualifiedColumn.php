<?php

declare(strict_types=1);

namespace MariaStan\Analyser\FullyQualifiedColumn;

class ParentFullyQualifiedColumn implements FullyQualifiedColumn
{
	public function __construct(public readonly FullyQualifiedColumn $parentColumn)
	{
	}

	public static function getColumnType(): FullyQualifiedColumnTypeEnum
	{
		return FullyQualifiedColumnTypeEnum::PARENT;
	}
}
