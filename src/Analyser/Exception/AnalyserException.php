<?php

declare(strict_types=1);

namespace MariaStan\Analyser\Exception;

use MariaStan\Analyser\AnalyserError;
use MariaStan\Analyser\AnalyserErrorTypeEnum;
use RuntimeException;
use Throwable;

class AnalyserException extends RuntimeException
{
	public function __construct(
		string $message = '',
		public readonly AnalyserErrorTypeEnum $errorType = AnalyserErrorTypeEnum::OTHER,
		int $code = 0,
		?Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public static function fromAnalyserError(AnalyserError $error): self
	{
		return new self($error->message, $error->type);
	}

	final public function toAnalyserError(): AnalyserError
	{
		return new AnalyserError($this->getMessage(), $this->errorType);
	}
}
