<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

enum IndexHintPurposeEnum: string
{
	case JOIN = 'JOIN';
	case ORDER_BY = 'ORDER_BY';
	case GROUP_BY = 'GROUP_BY';
}
