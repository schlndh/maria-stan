<?php

declare(strict_types=1);

namespace MariaStan\Ast\Expr\FunctionCall;

use MariaStan\Ast\Exception\InvalidArgumentException;
use MariaStan\Ast\Expr\Expr;
use MariaStan\Parser\Position;

use function count;

final class Count extends BaseFunctionCall
{
	/** @param array<Expr> $arguments */
	private function __construct(
		Position $startPosition,
		Position $endPosition,
		public readonly array $arguments,
		public readonly bool $isDistinct,
		public readonly bool $isCountAll,
	) {
		parent::__construct($startPosition, $endPosition);
	}

	public static function createCountAll(Position $startPosition, Position $endPosition): self
	{
		return new self($startPosition, $endPosition, [], false, true);
	}

	public static function createCount(Position $startPosition, Position $endPosition, Expr $argument): self
	{
		return new self($startPosition, $endPosition, [$argument], false, false);
	}

	/** @param non-empty-array<Expr> $arguments */
	public static function createCountDistinct(Position $startPosition, Position $endPosition, array $arguments): self
	{
		if (count($arguments) === 0) {
			throw new InvalidArgumentException('COUNT(DISTINCT ...) has to have at least one argument.');
		}

		return new self($startPosition, $endPosition, $arguments, true, false);
	}

	public function getFunctionName(): string
	{
		return 'COUNT';
	}

	/** @return array<Expr> */
	public function getArguments(): array
	{
		return $this->arguments;
	}

	public static function getFunctionCallType(): FunctionCallTypeEnum
	{
		return FunctionCallTypeEnum::COUNT;
	}
}
