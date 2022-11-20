<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo;

final class FunctionInfoRegistryFactory
{
	public function create(): FunctionInfoRegistry
	{
		return new FunctionInfoRegistry($this->createDefaultFunctionInfos());
	}

	/** @return array<FunctionInfo> */
	public function createDefaultFunctionInfos(): array
	{
		return [
			new Avg(),
			new Count(),
			new Date(),
			new DateFormat(),
			new IfFunction(),
			new MaxMin(),
			new Now(),
			new Sum(),
			new Value(),
		];
	}
}
