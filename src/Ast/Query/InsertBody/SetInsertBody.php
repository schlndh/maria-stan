<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\InsertBody;

use MariaStan\Ast\BaseNode;
use MariaStan\Ast\Expr\Assignment;
use MariaStan\Parser\Position;

final class SetInsertBody extends BaseNode implements InsertBody
{
	/** @param non-empty-array<Assignment> $assignments */
	public function __construct(Position $startPosition, Position $endPosition, public readonly array $assignments)
	{
		parent::__construct($startPosition, $endPosition);
	}

	public static function getInsertBodyType(): InsertBodyTypeEnum
	{
		return InsertBodyTypeEnum::SET;
	}
}
