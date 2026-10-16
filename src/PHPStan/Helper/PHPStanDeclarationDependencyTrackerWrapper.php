<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Helper;

use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Reflection\ClassReflection;

final class PHPStanDeclarationDependencyTrackerWrapper implements DependencyTracker
{
	public function __construct(
		private readonly DeclarationDependencyTracker $declarationDependencyTracker,
		private readonly ClassReflection $classReflection,
	) {
	}

	public function trackValueDependency(string $extensionClass, string $key): void
	{
		$this->declarationDependencyTracker->trackValueDependency($this->classReflection, $extensionClass, $key);
	}

	public function trackFileDependency(string $file): void
	{
		$this->declarationDependencyTracker->trackFileDependency($this->classReflection, $file);
	}

	public function trackDirectoryDependency(string $directory, string $pattern = '*'): void
	{
		$this->declarationDependencyTracker->trackDirectoryDependency($this->classReflection, $directory, $pattern);
	}

	public function trackClassDependency(string $className): void
	{
		$this->declarationDependencyTracker->trackClassDependency($this->classReflection, $className);
	}
}
