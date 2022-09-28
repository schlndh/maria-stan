<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\CastType;

use MariaStan\Parser\Position;

final class IntegerCastType extends BaseCastType
{
	public function __construct(Position $startPosition, Position $endPosition, public readonly bool $isSigned = true)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getCastType(): CastTypeEnum
	{
		return CastTypeEnum::INTEGER;
	}
}
