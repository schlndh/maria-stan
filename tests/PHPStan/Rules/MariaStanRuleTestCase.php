<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\VerbosityLevel;

use function count;

/**
 * Copied from {@see TypeInferenceTestCase}
 *
 * @template TRule of \PHPStan\Rules\Rule
 * @extends RuleTestCase<TRule>
 */
abstract class MariaStanRuleTestCase extends RuleTestCase
{
	/**
	 * @param callable(Node, Scope): void $callback
	 * @param array<string> $dynamicConstantNames
	 */
	public function processFile(string $file, callable $callback, array $dynamicConstantNames = []): void
	{
		$reflectionProvider = $this->createReflectionProvider();
		$typeSpecifier = self::getContainer()->getService('typeSpecifier');
		$fileHelper = self::getContainer()->getByType(\PHPStan\File\FileHelper::class);

		/** @phpstan-ignore-next-line */
		$resolver = new \PHPStan\Analyser\NodeScopeResolver(
			$reflectionProvider,
			self::getContainer()->getByType(\PHPStan\Reflection\InitializerExprTypeResolver::class),
			self::getReflector(),
			$this->getClassReflectionExtensionRegistryProvider(),
			$this->getParser(),
			self::getContainer()->getByType(\PHPStan\Type\FileTypeMapper::class),
			self::getContainer()->getByType(\PHPStan\PhpDoc\StubPhpDocProvider::class),
			self::getContainer()->getByType(\PHPStan\Php\PhpVersion::class),
			self::getContainer()->getByType(\PHPStan\PhpDoc\PhpDocInheritanceResolver::class),
			self::getContainer()->getByType(\PHPStan\File\FileHelper::class),
			$typeSpecifier,
			self::getContainer()->getByType(\PHPStan\DependencyInjection\Type\DynamicThrowTypeExtensionProvider::class),
			\true,
			\true,
			$this->getEarlyTerminatingMethodCalls(),
			$this->getEarlyTerminatingFunctionCalls(),
			\true,
		);
		$resolver->setAnalysedFiles(
			\array_map(
				static fn (string $file): string => $fileHelper->normalizePath($file),
				\array_merge([$file], $this->getAdditionalAnalysedFiles()),
			),
		);
		$scopeFactory = $this->createScopeFactory($reflectionProvider, $typeSpecifier, $dynamicConstantNames);
		$scope = $scopeFactory->create(\PHPStan\Analyser\ScopeContext::create($file));
		$resolver->processNodes($this->getParser()->parseFile($file), $scope, $callback);
	}

	/** @return array{string, array<array{string, int}>} [file, [error, line number]] */
	public function gatherAssertErrors(string $file): array
	{
		$asserts = [];
		$this->processFile(
			$file,
			function (\PhpParser\Node $node, \PHPStan\Analyser\Scope $scope) use (&$asserts): void {
				if (!$node instanceof \PhpParser\Node\Expr\FuncCall) {
					return;
				}

				$nameNode = $node->name;

				if (!$nameNode instanceof \PhpParser\Node\Name) {
					return;
				}

				$functionName = $nameNode->toString();

				/** @see assertFirstArgumentErrors() */
				if ($functionName !== 'MariaStan\\Testing\\assertFirstArgumentErrors') {
					return;
				}

				$errorLine = $node->getArgs()[0]->getLine();

				for ($i = 1; $i < count($node->getArgs()); $i++) {
					$arg = $node->getArgs()[$i];
					$argType = $scope->getType($arg->value);

					if (! $argType instanceof ConstantStringType) {
						$this->fail(
							'Expected ConstantString type got: ' . $argType->describe(VerbosityLevel::precise()),
						);
					}

					$asserts[] = [$argType->getValue(), $errorLine];
				}
			},
		);

		return [$file, $asserts];
	}

	/** @return array<string> */
	protected function getAdditionalAnalysedFiles(): array
	{
		return [];
	}

	/** @return array<array<string>> */
	protected function getEarlyTerminatingMethodCalls(): array
	{
		return [];
	}

	/** @return array<string> */
	protected function getEarlyTerminatingFunctionCalls(): array
	{
		return [];
	}
}
