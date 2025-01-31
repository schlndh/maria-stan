<?php

declare(strict_types=1);

namespace MariaStan\PHPStan;

use MariaStan\DbReflection\DbReflection;
use MariaStan\DbReflection\Exception\DbReflectionException;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;

class MariaStanResultCacheMetaExtension implements ResultCacheMetaExtension
{
	public function __construct(private readonly DbReflection $dbReflection)
	{
	}

	public function getKey(): string
	{
		return 'mariaStan.dbReflection';
	}

	/** @throws DbReflectionException */
	public function getHash(): string
	{
		return $this->dbReflection->getHash();
	}
}
