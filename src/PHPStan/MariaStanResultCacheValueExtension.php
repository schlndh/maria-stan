<?php

declare(strict_types=1);

namespace MariaStan\PHPStan;

use MariaStan\Analyser\AnalyserResult;
use MariaStan\Analyser\ReferencedSymbol\ReferencedSymbolTypeEnum;
use MariaStan\Analyser\ReferencedSymbol\Table;
use MariaStan\DbReflection\DbReflection;
use MariaStan\DbReflection\Exception\DbReflectionException;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;

use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function serialize;
use function time;

use const JSON_THROW_ON_ERROR;

final class MariaStanResultCacheValueExtension implements ResultCacheValueExtension
{
	private const ALL_VIEWS_TYPE = 'allViews';

	public function __construct(private readonly DbReflection $dbReflection)
	{
	}

	public function trackDependencies(DependencyTracker $dependencyTracker, AnalyserResult $result): void
	{
		foreach ($result->referencedSymbols ?? [] as $symbol) {
			if (! $symbol instanceof Table) {
				continue;
			}

			$key = json_encode([
				'type' => $symbol::getReferencedSymbolType()->value,
				'name' => $symbol->name,
				'database' => $symbol->database,
			], JSON_THROW_ON_ERROR);
			$dependencyTracker->trackValueDependency(self::class, $key);
		}
	}

	public function trackAllViewsDependency(DependencyTracker $dependencyTracker): void
	{
		$key = json_encode(['type' => self::ALL_VIEWS_TYPE], JSON_THROW_ON_ERROR);
		$dependencyTracker->trackValueDependency(self::class, $key);
	}

	public function getValue(string $key): string
	{
		$symbol = json_decode($key, true);

		if (! is_array($symbol) || ! is_string($symbol['type'] ?? null)) {
			return self::getInvalidValue();
		}

		if ($symbol['type'] === self::ALL_VIEWS_TYPE) {
			try {
				$schema = $this->dbReflection->getViewDefinitions();

				return self::hash(serialize($schema));
			} catch (DbReflectionException $e) {
				return self::hash($e->getMessage());
			}
		}

		if ($symbol['type'] !== ReferencedSymbolTypeEnum::TABLE->value) {
			return self::getInvalidValue();
		}

		if (! is_string($symbol['name'] ?? null) || ! is_string($symbol['database'] ?? null)) {
			return self::getInvalidValue();
		}

		try {
			$schema = $this->dbReflection->findTableSchema($symbol['name'], $symbol['database']);

			return self::hash(serialize($schema));
		} catch (DbReflectionException $e) {
			return self::hash($e->getMessage());
		}
	}

	public function keyToResultCache(string $key): string
	{
		return $key;
	}

	public function keyFromResultCache(string $storedKey): string
	{
		return $storedKey;
	}

	private static function hash(string $s): string
	{
		return hash('xxh128', $s);
	}

	private static function getInvalidValue(): string
	{
		// Invalidate the cache on unexpected key.
		return (string) time();
	}
}
