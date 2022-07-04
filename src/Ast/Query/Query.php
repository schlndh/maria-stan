<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query;

use MariaStan\Ast\Node;

interface Query extends Node
{
	public static function getQueryType(): QueryTypeEnum;
}
