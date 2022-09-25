<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Ast\Expr\Column;
use MariaStan\Ast\Expr\ExprTypeEnum;
use MariaStan\Ast\Expr\FunctionCall;
use MariaStan\Ast\Expr\LiteralNull;
use MariaStan\Ast\Query\SelectQuery;
use MariaStan\Ast\Query\TableReference\Table;
use MariaStan\Ast\SelectExpr\RegularExpr;
use MariaStan\Ast\SelectExpr\SelectExpr;
use MariaStan\DatabaseTestCaseHelper;
use MariaStan\Parser\Exception\ParserException;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;
use stdClass;

use function assert;
use function implode;
use function in_array;

// phpcs:disable SlevomatCodingStandard.Exceptions.RequireNonCapturingCatch.NonCapturingCatchRequired
class MariaDbParserKeywordAsIdentifierTest extends TestCase
{
	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestFieldAliasData(): iterable
	{
		/** @phpstan-var array<TokenTypeEnum> $cases */
		$cases = TokenTypeEnum::cases();

		foreach ($cases as $tokenType) {
			yield "field alias - {$tokenType->value}" => [
				'select' => "SELECT 1 {$tokenType->value}",
			];
		}
	}

	/** @dataProvider provideTestFieldAliasData */
	public function testFieldAlias(string $select): void
	{
		$parserResult = null;
		$dbField = null;
		$dbException = null;
		$parserException = null;
		$parser = new MariaDbParser();

		try {
			$dbField = $this->getFieldFromSql($select);
		} catch (mysqli_sql_exception $dbException) {
		}

		try {
			$parserResult = $parser->parseSingleQuery($select);
		} catch (ParserException $parserException) {
		}

		$this->makeSureExceptionsMatch($dbException, $parserException);

		if ($dbException !== null) {
			// Make phpunit happy.
			$this->assertNotNull($parserException);

			return;
		}

		$this->assertNotNull($dbField);
		$this->assertNotNull($parserResult);
		$this->assertInstanceOf(SelectQuery::class, $parserResult);
		$this->assertCount(1, $parserResult->select);
		$this->assertSame($dbField->name, $this->getNameFromSelectExpr($select, $parserResult->select[0]));
	}

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestTableAliasData(): iterable
	{
		$tableName = 'parser_keyword_test';
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				id INT NULL
			);
		");

		/** @phpstan-var array<TokenTypeEnum> $cases */
		$cases = TokenTypeEnum::cases();

		foreach ($cases as $tokenType) {
			yield "table alias - {$tokenType->value}" => [
				'select' => "SELECT id FROM {$tableName} {$tokenType->value};",
			];
		}
	}

	/** @dataProvider provideTestTableAliasData */
	public function testTableAlias(string $select): void
	{
		$parserResult = null;
		$dbField = null;
		$dbException = null;
		$parserException = null;
		$parser = new MariaDbParser();

		try {
			$dbField = $this->getFieldFromSql($select);
		} catch (mysqli_sql_exception $dbException) {
		}

		try {
			$parserResult = $parser->parseSingleQuery($select);
		} catch (ParserException $parserException) {
		}

		$this->makeSureExceptionsMatch($dbException, $parserException);

		if ($dbException !== null) {
			// Make phpunit happy.
			$this->assertNotNull($parserException);

			return;
		}

		$this->assertNotNull($dbField);
		$this->assertNotNull($parserResult);
		$this->assertInstanceOf(SelectQuery::class, $parserResult);
		$from = $parserResult->from;
		$this->assertNotNull($from);
		$this->assertInstanceOf(Table::class, $from);
		$this->assertSame($dbField->orgtable, $from->name);
		$this->assertSame($dbField->table, $from->alias);
	}

	/** @throws mysqli_sql_exception */
	private function getFieldFromSql(string $select): stdClass
	{
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$stmt = $db->query($select);
		$field = $stmt->fetch_field();
		$stmt->close();

		if ($field === false) {
			$this->fail('Failed to fetch field from: ' . $select);
		}

		// stdClass to stop phpstan from complaining about access to undefined field on object.
		assert($field instanceof stdClass);

		return $field;
	}

	/** @return iterable<string, array<mixed>> name => args */
	public function provideTestColumnNameData(): iterable
	{
		$tableName = 'parser_keyword_test_col';

		/** @phpstan-var array<TokenTypeEnum> $cases */
		$cases = TokenTypeEnum::cases();
		$columns = [];

		foreach ($cases as $tokenType) {
			$columns[] = "`{$tokenType->value}` TINYINT(1) NOT NULL";
		}

		$columnSql = implode(",\n", $columns);
		$db = DatabaseTestCaseHelper::getDefaultSharedConnection();
		$db->query("
			CREATE OR REPLACE TABLE {$tableName} (
				{$columnSql}
			);
		");

		foreach ($cases as $tokenType) {
			yield "column name - {$tokenType->value}" => [
				'select' => "SELECT {$tokenType->value} FROM {$tableName};",
			];

			yield "table.column - {$tokenType->value}" => [
				'select' => "SELECT {$tableName}.{$tokenType->value} FROM {$tableName};",
			];
		}
	}

	/** @dataProvider provideTestColumnNameData */
	public function testColumnName(string $select): void
	{
		$parserResult = null;
		$dbField = null;
		$dbException = null;
		$parserException = null;
		$parser = new MariaDbParser();

		try {
			$dbField = $this->getFieldFromSql($select);
		} catch (mysqli_sql_exception $dbException) {
		}

		if ($dbField !== null && $dbField->orgtable === '' && in_array($dbField->name, ['TRUE', 'FALSE'], true)) {
			// TODO: implement boolean literals
			$this->markTestIncomplete('Boolean literals are not yet implemented.');
		}

		try {
			$parserResult = $parser->parseSingleQuery($select);
		} catch (ParserException $parserException) {
		}

		$this->makeSureExceptionsMatch($dbException, $parserException);

		if ($dbException !== null) {
			// Make phpunit happy.
			$this->assertNotNull($parserException);

			return;
		}

		$this->assertNotNull($dbField);
		$this->assertNotNull($parserResult);
		$this->assertInstanceOf(SelectQuery::class, $parserResult);
		$this->assertCount(1, $parserResult->select);
		$firstSelectExpr = $parserResult->select[0];
		$this->assertInstanceOf(RegularExpr::class, $firstSelectExpr);
		$this->assertSame($dbField->name, $this->getNameFromSelectExpr($select, $firstSelectExpr));

		if ($dbField->orgtable === '') {
			switch ($dbField->name) {
				case 'CURRENT_DATE':
				case 'CURRENT_ROLE':
				case 'CURRENT_TIME':
				case 'CURRENT_TIMESTAMP':
				case 'CURRENT_USER':
				case 'LOCALTIME':
				case 'LOCALTIMESTAMP':
				case 'UTC_DATE':
				case 'UTC_TIME':
				case 'UTC_TIMESTAMP':
					$this->assertInstanceOf(FunctionCall\StandardFunctionCall::class, $firstSelectExpr->expr);
					break;
				case 'NULL':
					$this->assertInstanceOf(LiteralNull::class, $firstSelectExpr->expr);
					break;
				default:
					$this->fail("Unhandled non-column {$dbField->name}");
			}
		} else {
			$this->assertInstanceOf(Column::class, $firstSelectExpr->expr);
		}
	}

	private function getNameFromSelectExpr(string $query, SelectExpr $selectExpr): string
	{
		$this->assertInstanceOf(RegularExpr::class, $selectExpr);

		if ($selectExpr->alias !== null) {
			return $selectExpr->alias;
		}

		$expr = $selectExpr->expr;

		switch ($expr::getExprType()) {
			case ExprTypeEnum::COLUMN:
				assert($expr instanceof Column);

				return $expr->name;
			default:
				return $expr->getStartPosition()->findSubstringToEndPosition($query, $expr->getEndPosition());
		}
	}

	private function makeSureExceptionsMatch(
		?mysqli_sql_exception $dbException,
		?ParserException $parserException,
	): void {
		if ($dbException === null && $parserException !== null) {
			$this->fail("DB accepts the query, but parser fails with: {$parserException->getMessage()}");
		}

		if ($dbException !== null && $parserException === null) {
			$this->fail("Parser accepts the query, even though DB fails with: {$dbException->getMessage()}");
		}
	}
}
