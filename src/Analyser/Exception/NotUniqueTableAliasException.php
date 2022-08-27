<?php

declare(strict_types=1);

namespace MariaStan\Analyser\Exception;

use MariaStan\Util\MariaDbErrorCodes;
use Throwable;

class NotUniqueTableAliasException extends AnalyserException
{
	public function __construct(string $message = "", ?Throwable $previous = null)
	{
		parent::__construct($message, MariaDbErrorCodes::ER_NONUNIQ_TABLE, $previous);
	}
}
