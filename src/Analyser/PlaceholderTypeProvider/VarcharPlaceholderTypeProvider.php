<?php

declare(strict_types=1);

namespace MariaStan\Analyser\PlaceholderTypeProvider;

use MariaStan\Ast\Expr\Placeholder;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\VarcharType;

// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
final class VarcharPlaceholderTypeProvider implements PlaceholderTypeProvider
{
	public function getPlaceholderDbType(Placeholder $placeholder): DbType
	{
		return new VarcharType();
	}

	public function isPlaceholderNullable(Placeholder $placeholder): bool
	{
		return true;
	}
}
