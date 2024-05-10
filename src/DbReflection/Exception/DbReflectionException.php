<?php

declare(strict_types=1);

namespace MariaStan\DbReflection\Exception;

use MariaStan\Analyser\AnalyserError;
use MariaStan\Analyser\AnalyserErrorTypeEnum;
use RuntimeException;

class DbReflectionException extends RuntimeException
{
	public function getAnalyserErrorType(): AnalyserErrorTypeEnum
	{
		return AnalyserErrorTypeEnum::DB_REFLECTION;
	}

	final public function toAnalyserError(): AnalyserError
	{
		return new AnalyserError($this->getMessage(), $this->getAnalyserErrorType());
	}
}
