<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;

final class AnalyserResultPHPStanParams
{
	/**
	 * @param non-empty-array<ConstantArrayType> $rowTypes
	 * @param non-empty-array<ConstantIntegerType> $positionalPlaceholderCounts
	 */
	public function __construct(public readonly array $rowTypes, public readonly array $positionalPlaceholderCounts)
	{
	}
}
