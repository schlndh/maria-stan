<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

enum ColumnInfoTableTypeEnum: string
{
	case TABLE = 'TABLE';
	case SUBQUERY = 'SUBQUERY';
}
