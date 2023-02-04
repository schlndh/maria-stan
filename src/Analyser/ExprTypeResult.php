<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

use MariaStan\Schema\DbType\DbType;

final class ExprTypeResult
{
	public function __construct(
		public readonly DbType $type,
		public readonly bool $isNullable,
		public readonly ?ColumnInfo $column = null,
		public readonly ?AnalyserKnowledgeBase $knowledgeBase = null,
	) {
	}
}
