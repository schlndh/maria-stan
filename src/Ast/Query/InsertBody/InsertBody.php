<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\InsertBody;

use MariaStan\Ast\Node;

interface InsertBody extends Node
{
	public static function getInsertBodyType(): InsertBodyTypeEnum;
}
