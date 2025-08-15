<?php

declare(strict_types=1);

namespace MariaStan\Database\FunctionInfo\FunctionInfoHelper;

use MariaStan\Ast\Expr\FunctionCall\WindowFunctionCall;
use MariaStan\Ast\WindowFrame;
use MariaStan\Ast\WindowFrameTypeEnum;
use MariaStan\Database\FunctionInfo\FunctionInfoHelper;
use MariaStan\TestCaseHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanWindowFrameBeEmptyTest extends TestCase
{
	/** @return iterable<string, array<string, mixed>> */
	public static function provideTestData(): iterable
	{
		$frames = [
			'UNBOUNDED PRECEDING' => false,
			'CURRENT ROW' => false,
			'3 PRECEDING' => false,
			'BETWEEN CURRENT ROW AND CURRENT ROW' => false,
			'BETWEEN 3 PRECEDING AND CURRENT ROW' => false,
			'BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW' => false,
			'BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING' => false,
			'BETWEEN CURRENT ROW AND 3 FOLLOWING' => false,
			'BETWEEN 3 PRECEDING AND 2 PRECEDING' => true,
			'BETWEEN 1 PRECEDING AND 2 PRECEDING' => true,
			'BETWEEN UNBOUNDED PRECEDING AND 2 PRECEDING' => true,
			'BETWEEN 1 FOLLOWING AND 2 FOLLOWING' => true,
			'BETWEEN 1 FOLLOWING AND UNBOUNDED FOLLOWING' => true,
		];

		foreach (WindowFrameTypeEnum::cases() as $type) {
			foreach ($frames as $frame => $expectedResult) {
				yield $type->value . ' ' . $frame => [
					'frameSql' => $type->value . ' ' . $frame,
					'expectedResult' => $expectedResult,
				];
			}
		}
	}

	#[DataProvider('provideTestData')]
	public function test(string $frameSql, bool $expectedResult): void
	{
		$frame = self::parseWindowFrame($frameSql);
		$result = FunctionInfoHelper::canWindowFrameBeEmpty($frame);
		$this->assertSame($expectedResult, $result);
	}

	private static function parseWindowFrame(string $frameSql): WindowFrame
	{
		$parser = TestCaseHelper::createParser();
		$expr = $parser->parseSingleExpression("AVG(1) OVER(ORDER BY id {$frameSql})");
		self::assertInstanceOf(WindowFunctionCall::class, $expr);
		self::assertNotNull($expr->frame);

		return $expr->frame;
	}
}
