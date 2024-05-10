<?php

declare(strict_types=1);

namespace MariaStan\DbReflection\Exception;

use MariaStan\Analyser\AnalyserErrorTypeEnum;

class TableDoesNotExistException extends DatabaseException
{
	public function getAnalyserErrorType(): AnalyserErrorTypeEnum
	{
		return AnalyserErrorTypeEnum::UNKNOWN_TABLE;
	}
}
