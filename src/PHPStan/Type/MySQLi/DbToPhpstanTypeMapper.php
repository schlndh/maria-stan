<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

class DbToPhpstanTypeMapper
{
	public function mapDbTypeToPhpstanType(DbType $dbType): Type
	{
		return match ($dbType::getTypeEnum()) {
			DbTypeEnum::INT => new IntegerType(),
			DbTypeEnum::VARCHAR => new StringType(),
			DbTypeEnum::MIXED => TypeCombinator::union(new IntegerType(), new StringType(), new FloatType()),
		};
	}
}
