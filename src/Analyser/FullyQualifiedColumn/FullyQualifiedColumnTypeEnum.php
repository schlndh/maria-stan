<?php

declare(strict_types=1);

namespace MariaStan\Analyser\FullyQualifiedColumn;

enum FullyQualifiedColumnTypeEnum: string
{
	/** @see TableFullyQualifiedColumn */
	case TABLE = 'TABLE';
	case FIELD_LIST = 'FIELD_LIST';
	case PARENT = 'PARENT';
}
