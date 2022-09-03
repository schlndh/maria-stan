<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\Schema\DbType\DbType;
use MariaStan\Schema\DbType\DbTypeEnum;
use MariaStan\Schema\DbType\EnumType;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_map;
use function assert;

class DbToPhpstanTypeMapper
{
	public function mapDbTypeToPhpstanType(DbType $dbType): Type
	{
		switch ($dbType::getTypeEnum()) {
			case DbTypeEnum::INT:
				return new IntegerType();
			case DbTypeEnum::VARCHAR:
			case DbTypeEnum::DATETIME:
				return new StringType();
			case DbTypeEnum::DECIMAL:
				return new IntersectionType([new StringType(), new AccessoryNumericStringType()]);
			case DbTypeEnum::FLOAT:
				return new FloatType();
			case DbTypeEnum::NULL:
				return new NullType();
			case DbTypeEnum::ENUM:
				assert($dbType instanceof EnumType);

				return TypeCombinator::union(
					...array_map(static fn (string $c) => new ConstantStringType($c), $dbType->cases),
				);
			case DbTypeEnum::TUPLE:
				return new ErrorType();
			default:
				return TypeCombinator::union(new IntegerType(), new StringType(), new FloatType());
		}
	}
}
