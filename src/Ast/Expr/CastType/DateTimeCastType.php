<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\CastType;

use MariaStan\Parser\Position;

final class DateTimeCastType extends BaseCastType
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly ?int $microsecondPrecision = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function getCastType(): CastTypeEnum
	{
		return CastTypeEnum::DATETIME;
	}
}
