<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

use function strtoupper;

final class FunctionInfoRegistry
{
	/** @var array<string, FunctionInfo> */
	private readonly array $functionInfoMap ;

	/** @param array<FunctionInfo> $functionInfos */
	public function __construct(array $functionInfos = [])
	{
		$map = [];

		foreach ($functionInfos as $functionInfo) {
			foreach ($functionInfo->getSupportedFunctionNames() as $functionName) {
				$map[strtoupper($functionName)] = $functionInfo;
			}
		}

		$this->functionInfoMap = $map;
	}

	public function findFunctionInfoByFunctionName(string $functionName): ?FunctionInfo
	{
		return $this->functionInfoMap[strtoupper($functionName)] ?? null;
	}
}
