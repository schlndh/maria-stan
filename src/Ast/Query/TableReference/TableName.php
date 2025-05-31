<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class TableName extends BaseNode
{
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly string $name,
		// TODO: consider datbaseName during analysis
		public readonly ?string $databaseName = null,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
