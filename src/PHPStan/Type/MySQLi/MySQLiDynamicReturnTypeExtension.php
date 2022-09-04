<?php

declare(strict_types=1);

namespace MariaStan\PHPStan\Type\MySQLi;

use MariaStan\Analyser\Analyser;
use MariaStan\Analyser\Exception\AnalyserException;
use MariaStan\PHPStan\PHPStanReturnTypeHelper;
use mysqli_result;
use mysqli_stmt;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

use function in_array;

class MySQLiDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
	public function __construct(
		private readonly Analyser $analyser,
		private readonly PHPStanReturnTypeHelper $phpstanHelper,
	) {
	}

	public function getClass(): string
	{
		return \mysqli::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		return in_array($methodReflection->getName(), ['query', 'prepare'], true);
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope,
	): ?Type {
		$queryType = $scope->getType($methodCall->getArgs()[0]->value);

		if (! $queryType instanceof ConstantStringType) {
			return null;
		}

		try {
			$analyzerResult = $this->analyser->analyzeQuery($queryType->getValue());
		} catch (AnalyserException) {
			return null;
		}

		$rowType = $this->phpstanHelper->getRowTypeFromFields($analyzerResult->resultFields);
		$placeholderCountType = new ConstantIntegerType($analyzerResult->positionalPlaceholderCount);
		$returnClass = match ($methodReflection->getName()) {
			'query' => mysqli_result::class,
			'prepare' => mysqli_stmt::class,
			default => null,
		};

		return $rowType !== null && $returnClass !== null
			? new GenericObjectType($returnClass, [$rowType, $placeholderCountType])
			: null;
	}
}
