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
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractDatabase::class)]
#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(SpreadsheetDatabase::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(Index::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class SpreadsheetDatabaseMergeTest extends TestCase
{
    public function testLoadingMergesRowsAndTablesWithoutSharingOrChangingTheSource(): void
    {
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $target->load([
            'products' => [['id' => 'P1', 'name' => 'Old'], ['id' => 'P2', 'name' => 'Keep']],
            'audit' => [['message' => 'Keep this sheet']],
        ]);
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load([
            'products' => [['id' => 'P1', 'name' => 'Updated'], ['id' => 'P3', 'name' => 'New']],
            'notes' => [['text' => 'New sheet']],
        ]);
        $before = $source->spreadsheet()->toArray();
        $this->assertSame(3, $target->load($source));
        $this->assertSame([
            ['id' => 'P1', 'name' => 'Updated'],
            ['id' => 'P2', 'name' => 'Keep'],
            ['id' => 'P3', 'name' => 'New'],
        ], $target->data(['table' => 'products']));
        $this->assertSame([['message' => 'Keep this sheet']], $target->data(['table' => 'audit']));
        $this->assertSame([['text' => 'New sheet']], $target->data(['table' => 'notes']));
        $this->assertSame($before, $source->spreadsheet()->toArray());
        $this->assertNotSame($source->spreadsheet(), $target->spreadsheet());
        $source->load(['products' => [['id' => 'P1', 'name' => 'Source only']]]);
        $this->assertSame('Updated', $target->data(['table' => 'products'])[0]['name']);
        $target->load(['notes' => [['text' => 'Target only']]]);
        $this->assertSame([['text' => 'New sheet']], $source->data(['table' => 'notes']));
    }

    /** @return iterable<string, array{array<string, bool>, bool}> */
    public static function replacementOptions(): iterable
    {
        yield 'drop data' => [['dropData' => true], true];
        yield 'drop tables' => [['dropTables' => true], true];
        yield 'drop database' => [['dropDatabase' => true], false];
        yield 'drop database takes precedence' => [['dropDatabase' => true, 'dropTables' => true, 'dropData' => true], false];
    }

    /** @param array<string, bool> $options */
    #[DataProvider('replacementOptions')]
    public function testReplacementOptionsLoadRowsAndPreserveUnrelatedTablesUnlessDroppingTheDatabase(array $options, bool $keepAudit): void
    {
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $target->load(['products' => [['id' => 'P1']], 'audit' => [['message' => 'Keep']]]);
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load(['products' => [['id' => 'P2']]]);
        $this->assertSame(1, $target->load($source, $options));
        $this->assertSame([['id' => 'P2']], $target->data(['table' => 'products']));
        $this->assertSame($keepAudit, $target->spreadsheet()->hasSheet('audit'));
        $this->assertSame($keepAudit, $target->schema()->hasTable('audit'));
    }

    public function testAnEmptyTypedTableKeepsItsColumnsAndExplicitPrimaryKey(): void
    {
        $table = (new Table('products'))
            ->addColumn(new Column('id', 'integer'))
            ->addColumn((new Column('sku', 'string'))->setNullable(false)->setLength(30)->setDefault('Unknown'))
            ->setPrimaryKey(['sku']);
        $source = new SpreadsheetDatabase((new SpreadsheetSchemaTarget())->applySchema((new Schema())->addTable($table)));
        $sourceBefore = $source->spreadsheet()->toArray();
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $this->assertSame(0, $target->load($source));
        $restored = $target->schema()->getTable('products');
        $this->assertNotNull($restored);
        $this->assertSame(['sku'], $restored->getPrimaryKey());
        $this->assertSame('integer', $restored->getColumn('id')?->getType());
        $sku = $restored->getColumn('sku');
        $this->assertNotNull($sku);
        $this->assertSame(30, $sku->getLength());
        $this->assertSame('Unknown', $sku->getDefault());
        $this->assertFalse($sku->isNullable());
        $this->assertSame([], $target->data(['table' => 'products']));
        $this->assertSame($sourceBefore, $source->spreadsheet()->toArray());
        $this->assertSame(1, $target->load(['products' => [['id' => 1, 'sku' => 'S1']]]));
        $this->assertSame(1, $target->load(['products' => [['id' => 2, 'sku' => 'S1']]]));
        $this->assertSame([['id' => 2, 'sku' => 'S1']], $target->data(['table' => 'products']));
    }

    public function testAnIncompatibleTableRejectsTheWholeLoadBeforeChangingEarlierTables(): void
    {
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $target->load(['notes' => [['text' => 'Keep']], 'products' => [['id' => 'P1', 'name' => 'Keep']]]);
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load(['notes' => [['text' => 'Incoming']], 'products' => [['id' => 'P2', 'price' => 10]]]);
        $targetBefore = $target->spreadsheet()->toArray();
        $sourceBefore = $source->spreadsheet()->toArray();
        try {
            $target->load($source);
            $this->fail('Incompatible schemas must fail before applying any table.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('products', $exception->getMessage());
        }
        $this->assertSame($targetBefore, $target->spreadsheet()->toArray());
        $this->assertSame($sourceBefore, $source->spreadsheet()->toArray());
        $this->assertSame(2, $target->load($source, ['dropTables' => true]));
        $this->assertSame([['id' => 'P2', 'price' => 10]], $target->data(['table' => 'products']));
    }

    public function testAnSqliteSourceLoadsItsDeclaredTablesWithTheirTypesAndPrimaryKeys(): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->connection()->executeStatement('CREATE TABLE products (sku TEXT PRIMARY KEY, price INTEGER NOT NULL)');
        $source->connection()->executeStatement("INSERT INTO products VALUES ('S1', 10)");
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $this->assertSame(1, $target->load($source));
        $this->assertSame([['sku' => 'S1', 'price' => 10]], $target->data(['table' => 'products']));
        $table = $target->schema()->getTable('products');
        $this->assertNotNull($table);
        $this->assertSame(['sku'], $table->getPrimaryKey());
        $this->assertSame('integer', $table->getColumn('price')?->getType());
        $this->assertSame(1, $source->connection()->fetchOne('SELECT COUNT(*) FROM products'));
    }
}
