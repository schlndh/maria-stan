<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

enum IndexHintTypeEnum: string
{
	case USE = 'USE';
	case FORCE = 'FORCE';
	case IGNORE = 'IGNORE';
}
