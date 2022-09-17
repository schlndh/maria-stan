<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;

final class AnalyserResultPHPStanParams
{
	public function __construct(
		public readonly ConstantArrayType $rowType,
		public readonly ConstantIntegerType $positionalPlaceholderCount,
	) {
	}
}
