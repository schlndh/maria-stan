<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\InsertBody;

enum InsertBodyTypeEnum: string
{
	/** @see SelectInsertBody */
	case SELECT = 'SELECT';

	/** @see SetInsertBody */
	case SET = 'SET';

	/** @see ValuesInsertBody */
	case VALUES = 'VALUES';
}
