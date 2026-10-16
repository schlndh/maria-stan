<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\AnalyserResult;
use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\Analyser\PlaceholderTypeProvider\PlaceholderTypeProvider;
use MariaStan\PHPStan\MariaStanResultCacheValueExtension;
use PHPStan\Analyser\DependencyTracker;

final class PHPStanAnalyser
{
	public function __construct(
		private readonly Analyser $analyser,
		private readonly MariaStanResultCacheValueExtension $mariaStanResultCacheValueExtension,
	) {
	}

	/**
	 * @param ?PlaceholderTypeProvider $placeholderTypeProvider Pass PlaceholderTypeProvider to narrow down placeholder
	 * 	types. If you pass null, then all placeholders will get nullable mixed type. If you bind all placeholders as
	 * 	strings, you can pass {@see VarcharPlaceholderTypeProvider}, or {@see PHPStanTypeVarcharPlaceholderTypeProvider}
	 * @throws AnalyserException
	 * @see VarcharPlaceholderTypeProvider
	 * @see PHPStanTypeVarcharPlaceholderTypeProvider
	 */
	public function analyzeQuery(
		string $query,
		DependencyTracker $dependencyTracker,
		?PlaceholderTypeProvider $placeholderTypeProvider = null,
	): AnalyserResult {
		$result = $this->analyser->analyzeQuery($query, $placeholderTypeProvider);
		$this->mariaStanResultCacheValueExtension->trackDependencies($dependencyTracker, $result);

		return $result;
	}
}
