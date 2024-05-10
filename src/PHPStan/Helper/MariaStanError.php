<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function array_map;
use function array_values;

final class MariaStanError
{
	public function __construct(public readonly string $error, public readonly string $identifier)
	{
	}

	public function toPHPStanRuleError(): RuleError
	{
		return self::buildPHPSTanRuleError($this->error, $this->identifier);
	}

	public static function buildPHPSTanRuleError(string $message, string $identifier): RuleError
	{
		return RuleErrorBuilder::message($message)
			->identifier('mariaStan.' . $identifier)
			->build();
	}

	/**
	 * @param array<self> $errors
	 * @return array<RuleError>
	 */
	public static function arrayToPHPStanRuleErrors(array $errors): array
	{
		return array_map(static fn (self $e) => $e->toPHPStanRuleError(), $errors);
	}

	/**
	 * @param array<self> $errors
	 * @return list<self>
	 */
	public static function getUniqueErrors(array $errors): array
	{
		$result = [];

		foreach ($errors as $error) {
			$result[$error->identifier . ':' . $error->error] = $error;
		}

		return array_values($result);
	}
}
