<?php

declare(strict_types=1);

namespace MariaStan\Analyser;

final class AnalyserKnowledgeBase
{
	/**
	 * @param array<string, array<string, bool>> $columnNullability table alias => column name => nullability
	 *    (true = always NULL, false = never NULL)
	 * @param bool|null $truthiness (true = always true, false = always false, null => unknown)
	 */
	private function __construct(public readonly array $columnNullability, public readonly ?bool $truthiness)
	{
	}

	public static function createForSingleColumn(ColumnInfo $columnInfo, bool $nullability): self
	{
		return new self([$columnInfo->tableAlias => [$columnInfo->name => $nullability]], null);
	}

	public static function createFixed(bool $truthiness): self
	{
		return new self([], $truthiness);
	}

	public static function createEmpty(): self
	{
		return new self([], null);
	}

	public function and(self $other): self
	{
		$res = self::tryTrivialAnd($this, $other);
		$res ??= self::tryTrivialAnd($other, $this);

		if ($res !== null) {
			return $res;
		}

		$mergedColumnNullability = $this->columnNullability;

		foreach ($other->columnNullability as $tableAlias => $columns) {
			foreach ($columns as $column => $otherNullability) {
				$thisNullability = $this->columnNullability[$tableAlias][$column] ?? null;

				if ($thisNullability !== null && $thisNullability !== $otherNullability) {
					return self::createFixed(false);
				}

				$mergedColumnNullability[$tableAlias][$column] = $otherNullability;
			}
		}

		return new self($mergedColumnNullability, null);
	}

	public function or(self $other): self
	{
		$res = self::tryTrivialOr($this, $other);
		$res ??= self::tryTrivialOr($other, $this);

		if ($res !== null) {
			return $res;
		}

		$mergedColumnNullability = $this->columnNullability;

		foreach ($this->columnNullability as $tableAlias => $columns) {
			foreach ($columns as $column => $thisNullability) {
				$otherNullability = $other->columnNullability[$tableAlias][$column] ?? null;

				if ($otherNullability === null || $thisNullability !== $otherNullability) {
					unset($mergedColumnNullability[$tableAlias][$column]);
				}
			}
		}

		return new self($mergedColumnNullability, null);
	}

	public function removeTruthiness(): self
	{
		if ($this->truthiness === null) {
			return $this;
		}

		return new self($this->columnNullability, null);
	}

	private static function tryTrivialAnd(self $a, self $b): ?self
	{
		if ($a->truthiness === true) {
			return $b;
		}

		return $a->truthiness === false
			? $a
			: null;
	}

	private static function tryTrivialOr(self $a, self $b): ?self
	{
		if ($a->truthiness === true) {
			return $a;
		}

		return $a->truthiness === false
			? $b
			: null;
	}
}
