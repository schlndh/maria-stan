<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

class DbToPhpstanTypeMapper
{
	public function mapDbTypeToPhpstanType(DbType $dbType): Type
	{
		return match ($dbType::getTypeEnum()) {
			DbTypeEnum::INT => new IntegerType(),
			DbTypeEnum::VARCHAR, DbTypeEnum::DATETIME => new StringType(),
			DbTypeEnum::DECIMAL => new IntersectionType([new StringType(), new AccessoryNumericStringType()]),
			DbTypeEnum::FLOAT => new FloatType(),
			DbTypeEnum::NULL => new NullType(),
			DbTypeEnum::MIXED => TypeCombinator::union(new IntegerType(), new StringType(), new FloatType()),
		};
	}
}
