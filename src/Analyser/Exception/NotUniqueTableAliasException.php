<?php

declare(strict_types=1);

namespace MariaStan\Analyser\Exception;

use MariaStan\Analyser\AnalyserErrorBuilder;
use MariaStan\Analyser\AnalyserErrorTypeEnum;
use MariaStan\Util\MariaDbErrorCodes;
use Throwable;

class NotUniqueTableAliasException extends AnalyserException
{
	public function __construct(string $table, ?Throwable $previous = null)
	{
		parent::__construct(
			AnalyserErrorBuilder::createNotUniqueTableAliasErrorMessage($table),
			AnalyserErrorTypeEnum::NON_UNIQUE_TABLE_ALIAS,
			MariaDbErrorCodes::ER_NONUNIQ_TABLE,
			$previous,
		);
	}
}
