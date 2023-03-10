<?php

declare(strict_types=1);

namespace MariaStan\Analyser\FullyQualifiedColumn;

interface FullyQualifiedColumn
{
	public static function getColumnType(): FullyQualifiedColumnTypeEnum;
}
