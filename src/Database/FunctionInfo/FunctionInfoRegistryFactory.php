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
			new Abs(),
			new Avg(),
			new Cast(),
			new CeilFloor(),
			new Coalesce(),
			new Concat(),
			new Count(),
			new Curdate(),
			new Date(),
			new DateAddSub(),
			new DateFormat(),
			new FirstValue(),
			new FoundRows(),
			new GreatestLeast(),
			new GroupConcat(),
			new IfFunction(),
			new LowerUpper(),
			new MaxMin(),
			new Now(),
			new Replace(),
			new Round(),
			new Sum(),
			new Trim(),
			new Value(),
			new Year(),
		];
	}
}
