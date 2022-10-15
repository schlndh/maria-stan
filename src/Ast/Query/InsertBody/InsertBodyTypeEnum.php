<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\InsertBody;

enum InsertBodyTypeEnum: string
{
	/** @see ValuesInsertBody */
	case VALUES = 'VALUES';
	// TODO: INSERT ... SET
	// TODO: INSERT ... SELECT
}
