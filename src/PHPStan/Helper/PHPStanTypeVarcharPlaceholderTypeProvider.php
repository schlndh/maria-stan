<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use MariaStan\Analyser\PlaceholderTypeProvider\PlaceholderTypeProvider;
use MariaStan\Ast\Expr\Placeholder;
use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\EnumType;
use MariaStan\Schema\DbType\NullType;
use MariaStan\Schema\DbType\VarcharType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\TypeCombinator;

use function array_map;
use function array_unique;
use function count;

class PHPStanTypeVarcharPlaceholderTypeProvider implements PlaceholderTypeProvider
{
	/** @param array<int|string, \PHPStan\Type\Type> $placeholderPHPStanTypes */
	public function __construct(private readonly array $placeholderPHPStanTypes)
	{
	}

	public function getPlaceholderDbType(Placeholder $placeholder): DbType
	{
		$phpstanType = $this->placeholderPHPStanTypes[$placeholder->name] ?? null;

		if ($phpstanType === null) {
			return new VarcharType();
		}

		if ($phpstanType->isNull()->yes()) {
			return new NullType();
		}

		$phpstanType = TypeCombinator::removeNull($phpstanType);
		$constantStrings = $phpstanType->toString()->getConstantStrings();

		if (count($constantStrings) === 0) {
			return new VarcharType();
		}

		$values = array_unique(array_map(static fn (ConstantStringType $t) => $t->getValue(), $constantStrings));

		return new EnumType($values);
	}

	public function isPlaceholderNullable(Placeholder $placeholder): bool
	{
		$phpstanType = $this->placeholderPHPStanTypes[$placeholder->name] ?? null;

		if ($phpstanType === null) {
			return true;
		}

		return ! $phpstanType->isNull()->no();
	}
}
