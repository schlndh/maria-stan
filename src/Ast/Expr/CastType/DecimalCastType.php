<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\CastType;

use MariaStan\Parser\Position;

final class DecimalCastType extends BaseCastType
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly int $maxDigits,
		public readonly int $maxDecimals,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getCastType(): CastTypeEnum
	{
		return CastTypeEnum::DECIMAL;
	}
}
