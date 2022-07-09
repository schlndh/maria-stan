<?php

declare(strict_types=1);

namespace MariaStan\Ast;

use MariaStan\Parser\Position;

interface Node
{
	/** Returns start position of the first token of the node. */
	public function getStartPosition(): Position;

	/** Returns end position of the last token of the node. */
	public function getEndPosition(): Position;
}
