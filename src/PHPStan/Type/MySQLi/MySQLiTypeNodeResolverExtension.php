<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\PHPStan\Helper\MySQLi\PHPStanMySQLiHelper;
use MariaStan\PHPStan\Helper\PHPStanDeclarationDependencyTrackerWrapper;
use MariaStan\Util\MysqliUtil;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;
use function sprintf;

use const MYSQLI_ASSOC;

class MySQLiTypeNodeResolverExtension implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
	private TypeNodeResolver $typeNodeResolver;

	public function __construct(
		private readonly PHPStanMySQLiHelper $phpstanMysqliHelper,
		private readonly DeclarationDependencyTracker $declarationDependencyTracker,
		private readonly ReflectionProvider $reflectionProvider,
	) {
	}

	public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
	{
		$this->typeNodeResolver = $typeNodeResolver;
	}

	public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
	{
		if (
			! $typeNode instanceof GenericTypeNode
			|| count($typeNode->genericTypes) !== 1
			// The phpdoc is only supported in classes, because we can't track cache dependencies otherwise.
			|| $nameScope->getClassName() === null
		) {
			return null;
		}

		$mainType = $this->typeNodeResolver->resolve($typeNode->type, $nameScope);

		if (
			$mainType->getObjectClassNames() !== [MySQLiTableAssocRowType::class]
			|| ! $this->reflectionProvider->hasClass($nameScope->getClassName())
		) {
			return null;
		}

		$tableNameType = $this->typeNodeResolver->resolve($typeNode->genericTypes[0], $nameScope);
		$tableNames = $tableNameType->getConstantStrings();

		if ($tableNames === []) {
			return null;
		}

		$types = [];
		$classReflection = $this->reflectionProvider->getClass($nameScope->getClassName());
		$dependencyTracker = new PHPStanDeclarationDependencyTrackerWrapper(
			$this->declarationDependencyTracker,
			$classReflection,
		);

		foreach ($tableNames as $tableName) {
			$types[] = $this->phpstanMysqliHelper->getRowTypeFromStringQuery(
				sprintf('SELECT * FROM %s', MysqliUtil::quoteIdentifier($tableName->getValue())),
				MYSQLI_ASSOC,
				$dependencyTracker,
			);
		}

		return TypeCombinator::union(...$types);
	}
}
