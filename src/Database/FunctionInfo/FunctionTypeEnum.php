<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

enum FunctionTypeEnum: string
{
	case SIMPLE = 'SIMPLE';
	case AGGREGATE = 'AGGREGATE';
	case WINDOW = 'WINDOW';
	case AGGREGATE_OR_WINDOW = 'AGGREGATE_OR_WINDOW';
}
