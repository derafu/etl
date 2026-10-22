<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Database;

use Derafu\ETL\Database\Abstract\AbstractDatabase;
use Derafu\ETL\Database\DatabaseManager;
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractDatabase::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(SpreadsheetDatabase::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(Index::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class PrimaryKeyInferenceTest extends TestCase
{
    public function testAnExistingSheetWithoutMetadataInfersIdAndUsesItForUpdates(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet('products', [['id' => 'P1', 'name' => 'Original']], true);
        $database = new SpreadsheetDatabase($spreadsheet);
        $this->assertSame(['id'], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame(1, $database->load(['products' => [['id' => 'P1', 'name' => 'Updated']]]));
        $this->assertSame([['id' => 'P1', 'name' => 'Updated']], $database->data(['table' => 'products']));
    }

    public function testNewArrayTablesInferIdPersistItInMetadataAndGenerateTheSameSqlPrimaryKey(): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        $this->assertSame(1, $database->load(['products' => [['id' => 'P1', 'name' => 'Original']]]));
        $this->assertSame(['id'], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame(1, $database->load(['products' => [['id' => 'P1', 'name' => 'Updated']]]));
        $this->assertSame([['id' => 'P1', 'name' => 'Updated']], $database->data(['table' => 'products']));
        $restored = (new SpreadsheetSchemaSource())->extractSchema($database->spreadsheet());
        $this->assertSame(['id'], $restored->getTable('products')?->getPrimaryKey());
        $doctrine = (new DoctrineSchemaTarget())->applySchema($restored);
        $this->assertSame(['id'], $doctrine->getTable('products')->getPrimaryKey()?->getColumns());
    }

    /** @return iterable<string, array{array<string, bool>}> */
    public static function dropModes(): iterable
    {
        yield 'drop data' => [['dropData' => true]];
        yield 'drop tables' => [['dropTables' => true]];
        yield 'drop database' => [['dropDatabase' => true]];
    }

    /** @param array<string, bool> $options */
    #[DataProvider('dropModes')]
    public function testDropModesKeepOrInferTheIdPrimaryKeyConsistently(array $options): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet('products', [['id' => 'P1', 'name' => 'Original']], true);
        $database = new SpreadsheetDatabase($spreadsheet);
        $this->assertSame(1, $database->load(['products' => [['id' => 'P2', 'name' => 'Replacement']]], $options));
        $this->assertSame(['id'], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame(1, $database->load(['products' => [['id' => 'P2', 'name' => 'Updated']]]));
        $this->assertSame([['id' => 'P2', 'name' => 'Updated']], $database->data(['table' => 'products']));
    }

    public function testExplicitCompositeKeysTakePrecedenceOverAnIdColumn(): void
    {
        $table = (new Table('products'))
            ->addColumn(new Column('id', 'string'))
            ->addColumn(new Column('tenant', 'string'))
            ->addColumn(new Column('sku', 'string'))
            ->setPrimaryKey(['tenant', 'sku']);
        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema((new Schema())->addTable($table));
        $database = new SpreadsheetDatabase($spreadsheet);
        $this->assertSame(2, $database->load(['products' => [
            ['id' => 'P1', 'tenant' => 'A', 'sku' => 'SKU1'],
            ['id' => 'P1', 'tenant' => 'B', 'sku' => 'SKU1'],
        ]]));
        $this->assertSame(['tenant', 'sku'], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame(1, $database->load(['products' => [['id' => 'P2', 'tenant' => 'B', 'sku' => 'SKU1']]]));
        $this->assertSame([
            ['id' => 'P1', 'tenant' => 'A', 'sku' => 'SKU1'],
            ['id' => 'P2', 'tenant' => 'B', 'sku' => 'SKU1'],
        ], $database->data(['table' => 'products']));
    }

    public function testAnExplicitSchemaWithoutAPrimaryKeyDoesNotInferOneFromId(): void
    {
        $table = (new Table('products'))->addColumn(new Column('id', 'string'));
        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema((new Schema())->addTable($table));
        $database = new SpreadsheetDatabase($spreadsheet);
        $this->assertSame(2, $database->load(['products' => [['id' => 'P1'], ['id' => 'P1']]]));
        $this->assertSame([], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame([['id' => 'P1'], ['id' => 'P1']], $database->data(['table' => 'products']));
    }

    public function testInferenceIsCaseSensitiveAndDoesNotInventOtherPrimaryKeys(): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        $this->assertSame(4, $database->load([
            'products' => [['ID' => 'P1'], ['ID' => 'P1']],
            'items' => [['sku' => 'SKU1'], ['sku' => 'SKU1']],
        ]));
        $this->assertSame([], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame([], $database->schema()->getTable('items')?->getPrimaryKey());
        $this->assertCount(2, $database->data(['table' => 'products']));
        $this->assertCount(2, $database->data(['table' => 'items']));
    }

    public function testTheFirstRowsDefineAnInitiallyEmptyTableAndInferItsIdKey(): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        $counts = [$database->load(['products' => []]), $database->load(['products' => []])];
        $this->assertSame([0, 0], $counts);
        $this->assertSame(1, $database->load(['products' => [['id' => 'P1', 'name' => 'First']]]));
        $table = $database->schema()->getTable('products');
        $this->assertNotNull($table);
        $this->assertTrue($table->hasColumn('name'));
        $this->assertSame(['id'], $table->getPrimaryKey());
        $this->assertSame(1, $database->load(['products' => [['name' => 'Updated', 'id' => 'P1']]]));
        $this->assertSame([['id' => 'P1', 'name' => 'Updated']], $database->data(['table' => 'products']));
        $restored = (new SpreadsheetSchemaSource())->extractSchema($database->spreadsheet());
        $this->assertSame(['id'], $restored->getTable('products')?->getPrimaryKey());
    }

    public function testTheFirstRowsOfAnEmptyTableWithoutIdDoNotInventAPrimaryKey(): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        $database->load(['products' => []]);
        $counts = [
            $database->load(['products' => [['sku' => 'SKU1']]]),
            $database->load(['products' => [['sku' => 'SKU1']]]),
        ];
        $this->assertSame([1, 1], $counts);
        $this->assertSame([], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame([['sku' => 'SKU1'], ['sku' => 'SKU1']], $database->data(['table' => 'products']));
    }

    public function testDefiningTheColumnsOfAnEmptyTablePreservesItsExplicitPrimaryKey(): void
    {
        $table = (new Table('products'))->setPrimaryKey(['sku']);
        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema((new Schema())->addTable($table));
        $database = new SpreadsheetDatabase($spreadsheet);
        $this->assertSame(1, $database->load(['products' => [['id' => 'P1', 'sku' => 'SKU1']]]));
        $this->assertSame(['sku'], $database->schema()->getTable('products')?->getPrimaryKey());
        $this->assertSame(1, $database->load(['products' => [['id' => 'P2', 'sku' => 'SKU1']]]));
        $this->assertSame([['id' => 'P2', 'sku' => 'SKU1']], $database->data(['table' => 'products']));
    }

    public function testAfterDefiningAnEmptyTableLaterRowsMustRespectItsColumns(): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        $database->load(['products' => []]);
        $database->load(['products' => [['id' => 'P1', 'name' => 'First']]]);
        $before = $database->spreadsheet()->toArray();
        try {
            $database->load(['products' => [['id' => 'P2', 'name' => 'Other', 'extra' => true]]]);
            $this->fail('Column inference must only happen while no columns are defined.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must have the same columns', $exception->getMessage());
        }
        $this->assertSame($before, $database->spreadsheet()->toArray());
    }

    /** @return iterable<string, array{array<int, array<string, mixed>>, string}> */
    public static function invalidInferredKeys(): iterable
    {
        yield 'null id' => [[['id' => null, 'name' => 'Null key']], 'must be present and non-null'];
        yield 'duplicate id' => [[['id' => 'P1', 'name' => 'One'], ['id' => 'P1', 'name' => 'Two']], 'Duplicate primary key'];
    }

    /** @param array<int, array<string, mixed>> $rows */
    #[DataProvider('invalidInferredKeys')]
    public function testInvalidInferredKeysAreRejectedBeforeCreatingTheDocument(array $rows, string $message): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        try {
            $database->load(['products' => $rows]);
            $this->fail('The inferred primary key must enforce the same rules as a declared key.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame([], $database->spreadsheet()->getSheets());
    }
}
