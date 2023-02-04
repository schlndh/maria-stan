<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

enum AnalyserConditionTypeEnum: string
{
	case TRUTHY = 'TRUTHY';
	case FALSY = 'FALSY';
	case NULL = 'NULL';
	case NOT_NULL = 'NOT_NULL';
}
