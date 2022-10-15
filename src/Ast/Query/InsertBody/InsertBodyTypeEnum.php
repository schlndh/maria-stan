<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\InsertBody;

enum InsertBodyTypeEnum: string
{
	/** @see ValuesInsertBody */
	case VALUES = 'VALUES';

	/** @see SelectInsertBody */
	case SELECT = 'SELECT';
	// TODO: INSERT ... SET
}
