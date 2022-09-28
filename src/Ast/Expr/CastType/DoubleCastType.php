<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\CastType;

final class DoubleCastType extends BaseCastType
{
	public static function getCastType(): CastTypeEnum
	{
		return CastTypeEnum::DOUBLE;
	}
}
