<?php

declare(strict_types=1);

namespace MariaStan\Analyser\Exception;

use MariaStan\Analyser\AnalyserErrorTypeEnum;
use MariaStan\Util\MariaDbErrorCodes;
use Throwable;

class DuplicateFieldNameException extends AnalyserException
{
	public function __construct(string $fieldName, ?Throwable $previous = null)
	{
		parent::__construct(
			"Duplicate column name '{$fieldName}'",
			AnalyserErrorTypeEnum::DUPLICATE_COLUMN,
			MariaDbErrorCodes::ER_DUP_FIELDNAME,
			$previous,
		);
	}
}
