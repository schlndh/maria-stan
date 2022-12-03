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
			new Cast(),
			new CeilFloor(),
			new Coalesce(),
			new Count(),
			new Curdate(),
			new Date(),
			new DateAddSub(),
			new DateFormat(),
			new FoundRows(),
			new IfFunction(),
			new GroupConcat(),
			new MaxMin(),
			new Now(),
			new Round(),
			new Sum(),
			new Trim(),
			new Value(),
		];
	}
}
