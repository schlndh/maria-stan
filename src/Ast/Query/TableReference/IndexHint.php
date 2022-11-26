<?php

declare(strict_types=1);

namespace MariaStan\Ast\Query\TableReference;

use MariaStan\Ast\BaseNode;
use MariaStan\Parser\Position;

final class IndexHint extends BaseNode
{
	/** @param array<string> $columnNames */
	public function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly IndexHintTypeEnum $type,
		public readonly array $columnNames,
		public readonly IndexHintPurposeEnum $purpose = IndexHintPurposeEnum::JOIN,
	) {
		parent::__construct($startPosition, $endPosition);
	}
}
