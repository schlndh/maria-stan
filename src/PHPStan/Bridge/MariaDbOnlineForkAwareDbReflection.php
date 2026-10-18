<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Bridge;

use MariaStan\DbReflection\DbReflection;
use MariaStan\DbReflection\InformationSchemaParser;
use MariaStan\DbReflection\MariaDbOnlineDbReflection;
use MariaStan\DbReflection\SequenceEngineHandler;
use MariaStan\Schema\Table;

use function getmypid;

/**
 * PHPStan uses pcntl_fork to start workers (when available). This breaks mysqli connection. This class deals with it
 * by checking whether a fork occurred and reconnecting.
 */
class MariaDbOnlineForkAwareDbReflection implements DbReflection
{
	private ?MariaDbOnlineDbReflection $dbReflection = null;
	private int|false $pid;

	public function __construct(
		private readonly MySQLiFactory $mysqliFactory,
		private readonly string $defaultDatabase,
		private readonly InformationSchemaParser $schemaParser,
		private readonly SequenceEngineHandler $sequenceEngineHandler,
	) {
		$this->pid = false;
	}

	public function getDefaultDatabase(): string
	{
		return $this->defaultDatabase;
	}

	public function findTableSchema(string $table, ?string $database = null): Table
	{
		return $this->getDbReflection()->findTableSchema($table, $database);
	}

	public function findViewDefinition(string $view, ?string $database = null): string
	{
		return $this->getDbReflection()->findViewDefinition($view, $database);
	}

	/** @inheritDoc */
	public function getViewDefinitions(): array
	{
		return $this->getDbReflection()->getViewDefinitions();
	}

	public function getHash(): string
	{
		return $this->getDbReflection()->getHash();
	}

	private function getDbReflection(): MariaDbOnlineDbReflection
	{
		if ($this->dbReflection === null || $this->pid !== getmypid()) {
			// Don't close the old connection: it belongs to the parent process.
			$this->dbReflection = new MariaDbOnlineDbReflection(
				$this->mysqliFactory->create(),
				$this->defaultDatabase,
				$this->schemaParser,
				$this->sequenceEngineHandler,
			);
			$this->pid = getmypid();
		}

		return $this->dbReflection;
	}
}
