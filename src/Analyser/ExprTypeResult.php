<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Schema\DbType\DbType;

final class ExprTypeResult
{
	// TODO: add source: column vs dynamic
	public function __construct(public readonly DbType $type, public readonly bool $isNullable)
	{
	}
}
