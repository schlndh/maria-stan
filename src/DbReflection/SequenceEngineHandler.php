<?php

declare(strict_types=1);

namespace MariaStan\DbReflection;

use MariaStan\Schema\Column;
use MariaStan\Schema\DbType\UnsignedIntType;
use MariaStan\Schema\Table;

use function preg_match;

class SequenceEngineHandler
{
	public function handleSequenceTable(string $name, ?string $database): ?Table
	{
		// information_schema doesn't have sequences for some reason.
		if ($database === 'information_schema') {
			return null;
		}

		if (preg_match('/^seq_\d+_to_\d+(?:_step_(?P<step>\d+))?$/', $name, $matches) !== 1) {
			return null;
		}

		$step = (int) ($matches['step'] ?? 1);

		if ($step === 0) {
			return null;
		}

		// TODO: Report INSERT, REPLACE, UPDATE, DELETE, TRUNCATE as error:
		// Error in query (1031): Storage engine SEQUENCE of the table `...` doesn't have this option
		return new Table(
			$name,
			$database,
			[
				'seq' => new Column(
					'seq',
					new UnsignedIntType(),
					false,
				),
			],
		);
	}
}
