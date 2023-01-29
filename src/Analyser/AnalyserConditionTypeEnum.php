<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

enum AnalyserConditionTypeEnum: string
{
	case TRUTHY = 'TRUTHY';
	case FALSY = 'FALSY';
	case NULL = 'NULL';
	case NOT_NULL = 'NOT_NULL';

	public function negate(): self
	{
		return match ($this) {
			self::TRUTHY => self::FALSY,
			self::FALSY => self::TRUTHY,
			self::NULL => self::NOT_NULL,
			self::NOT_NULL => self::NULL,
		};
	}
}
