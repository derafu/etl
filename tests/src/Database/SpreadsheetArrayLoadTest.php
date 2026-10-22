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
use Derafu\Translation\TranslatorFactory;
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
final class SpreadsheetArrayLoadTest extends TestCase
{
    private function database(bool $primaryKey = true, bool $composite = false): SpreadsheetDatabase
    {
        $schema = new Schema('Store');
        $products = new Table('products');
        if ($composite) {
            $products->addColumn(new Column('tenant', 'string'));
        }
        $products->addColumn(new Column('id', 'string'));
        $products->addColumn(new Column('name', 'string'));
        $products->addColumn(new Column('note', 'string'));
        if ($primaryKey) {
            $products->setPrimaryKey($composite ? ['tenant', 'id'] : ['id']);
        }
        $schema->addTable($products);
        $schema->addTable((new Table('audit'))->addColumn(new Column('message', 'text')));
        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema($schema);
        $database = new SpreadsheetDatabase($spreadsheet);
        $sheet = $spreadsheet->getSheet('products');
        $this->assertNotNull($sheet);
        $sheet->setRows([
            ($composite ? ['tenant' => 'A'] : []) + ['id' => 'P1', 'name' => 'Laptop', 'note' => null],
            ($composite ? ['tenant' => 'B'] : []) + ['id' => 'P2', 'name' => 'Desktop', 'note' => 'Existing'],
        ]);
        $audit = $spreadsheet->getSheet('audit');
        $this->assertNotNull($audit);
        $audit->setRows([['message' => 'Keep audit history']]);
        return $database;
    }

    public function testDeclaredPrimaryKeysUpdateExistingRowsAndInsertNewRows(): void
    {
        $database = $this->database();
        $schemaBefore = $database->schema();
        $metadataBefore = $database->spreadsheet()->getSheet('__schema')?->getRows();
        $rows = [
            3 => ['name' => 'Updated laptop', 'note' => null, 'id' => 'P1'],
            8 => ['id' => 'P3', 'name' => 'Tablet', 'note' => 'New'],
        ];
        $this->assertSame(2, $database->load(['products' => $rows]));
        $this->assertSame([
            ['id' => 'P1', 'name' => 'Updated laptop', 'note' => null],
            ['id' => 'P2', 'name' => 'Desktop', 'note' => 'Existing'],
            ['id' => 'P3', 'name' => 'Tablet', 'note' => 'New'],
        ], $database->data(['table' => 'products']));
        $this->assertSame([['message' => 'Keep audit history']], $database->data(['table' => 'audit']));
        $this->assertSame($metadataBefore, $database->spreadsheet()->getSheet('__schema')?->getRows());
        $this->assertNotSame($schemaBefore, $database->schema());
        $this->assertSame([3, 8], array_keys($rows));
        $this->assertSame(0, $database->load([]));
    }

    public function testCompositeKeysMatchEveryComponent(): void
    {
        $database = $this->database(composite: true);
        $this->assertSame(2, $database->load(['products' => [
            ['tenant' => 'A', 'id' => 'P1', 'name' => 'Updated', 'note' => null],
            ['tenant' => 'B', 'id' => 'P1', 'name' => 'Other tenant', 'note' => null],
        ]]));
        $rows = $database->data(['table' => 'products']);
        $this->assertCount(3, $rows);
        $this->assertSame('Updated', $rows[0]['name']);
        $this->assertSame(['tenant' => 'B', 'id' => 'P1', 'name' => 'Other tenant', 'note' => null], $rows[2]);
    }

    public function testWithoutADeclaredPrimaryKeyEvenIdenticalIdsAreAppended(): void
    {
        $database = $this->database(primaryKey: false);
        $this->assertSame(2, $database->load(['products' => [
            ['id' => 'P1', 'name' => 'Repeated', 'note' => null],
            ['id' => 'P1', 'name' => 'Repeated', 'note' => null],
        ]]));
        $rows = $database->data(['table' => 'products']);
        $this->assertCount(4, $rows);
        $this->assertSame('Laptop', $rows[0]['name']);
        $this->assertSame($rows[2], $rows[3]);
    }

    public function testNewTablesPreserveNumericColumnNamesNullsAndScalarTypes(): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        $rows = [4 => [2024 => 7, '01' => null, 'active' => false], 9 => ['active' => true, '01' => 'Leading zero', 2024 => 8]];
        $this->assertSame(2, $database->load(['2024' => $rows, 'empty' => []]));
        $this->assertSame([
            [2024 => 7, '01' => null, 'active' => false],
            [2024 => 8, '01' => 'Leading zero', 'active' => true],
        ], $database->data(['table' => '2024']));
        $table = $database->schema()->getTable('2024');
        $this->assertNotNull($table);
        $this->assertTrue($table->hasColumn('2024'));
        $this->assertTrue($table->hasColumn('01'));
        $this->assertSame([], $database->data(['table' => 'empty']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRows(): iterable
    {
        yield 'not an array' => ['invalid'];
        yield 'row not an array' => [[123]];
        yield 'empty row' => [[[]]];
        yield 'missing column' => [[['id' => 'P1', 'name' => 'Missing note']]];
        yield 'additional column' => [[['id' => 'P1', 'name' => 'Extra', 'note' => null, 'extra' => true]]];
        yield 'null primary key' => [[['id' => null, 'name' => 'Null key', 'note' => null]]];
        yield 'missing primary key' => [[['name' => 'Missing key', 'note' => null]]];
        yield 'duplicate primary key' => [[['id' => 'P3', 'name' => 'First', 'note' => null], ['id' => 'P3', 'name' => 'Second', 'note' => null]]];
        yield 'later invalid row' => [[['id' => 'P3', 'name' => 'First', 'note' => null], false]];
    }

    #[DataProvider('invalidRows')]
    public function testAllRowsAreValidatedBeforeAnySheetOrMetadataChanges(mixed $rows): void
    {
        $database = $this->database();
        $before = $database->spreadsheet()->toArray();
        try {
            $database->load(['audit' => [['message' => 'Must not be applied']], 'products' => $rows]);
            $this->fail('Invalid input must be rejected before changing any sheet.');
        } catch (InvalidArgumentException $exception) {
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
        $this->assertSame($before, $database->spreadsheet()->toArray());
    }

    public function testNewTablesRejectInconsistentColumnsBeforeCreatingAnySheet(): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        try {
            $database->load(['new_table' => [['id' => 1], ['other' => 2]]]);
            $this->fail('Inconsistent columns must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must have the same columns', $exception->getMessage());
        }
        $this->assertSame([], $database->spreadsheet()->getSheets());
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidLoadOptions(): iterable
    {
        yield 'drop database' => [['dropDatabase' => 'true']];
        yield 'drop tables' => [['dropTables' => 1]];
        yield 'drop data' => [['dropData' => []]];
        yield 'schema sheet name' => [['schemaSheetName' => []]];
    }

    /** @param array<array-key, mixed> $options */
    #[DataProvider('invalidLoadOptions')]
    public function testInvalidOptionTypesAreRejected(array $options): void
    {
        $database = $this->database();
        $this->expectException(InvalidArgumentException::class);
        $database->load([], $options);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTableNames(): iterable
    {
        yield 'empty table name' => [''];
        yield 'schema sheet name' => ['__schema'];
    }

    #[DataProvider('invalidTableNames')]
    public function testInvalidAndReservedTableNamesAreRejected(string $name): void
    {
        $database = new SpreadsheetDatabase(new Spreadsheet());
        $this->expectException(InvalidArgumentException::class);
        $database->load([$name => []]);
    }

    public function testDropDataReplacesIncludedRowsButPreservesTheirStructureAndOtherTables(): void
    {
        $database = $this->database();
        $this->assertSame(1, $database->load(['products' => [['id' => 'P3', 'name' => 'New', 'note' => null]]], ['dropData' => true]));
        $this->assertSame([['id' => 'P3', 'name' => 'New', 'note' => null]], $database->data(['table' => 'products']));
        $table = $database->schema()->getTable('products');
        $this->assertNotNull($table);
        $this->assertSame(['id'], $table->getPrimaryKey());
        $this->assertSame([['message' => 'Keep audit history']], $database->data(['table' => 'audit']));
        $this->assertSame(0, $database->load(['products' => []], ['dropData' => true]));
        $this->assertSame([], $database->data(['table' => 'products']));
        $this->assertTrue($database->schema()->hasTable('products'));
    }

    public function testDropTablesRecreatesOnlyIncludedTablesAndTakesPrecedenceOverDropData(): void
    {
        $database = $this->database();
        $this->assertSame(1, $database->load(['products' => [['sku' => 'N1', 'price' => 12]]], ['dropTables' => true, 'dropData' => true]));
        $this->assertSame([['sku' => 'N1', 'price' => 12]], $database->data(['table' => 'products']));
        $table = $database->schema()->getTable('products');
        $this->assertNotNull($table);
        $this->assertSame([], $table->getPrimaryKey());
        $this->assertFalse($table->hasColumn('id'));
        $this->assertTrue($table->hasColumn('sku'));
        $this->assertSame([['message' => 'Keep audit history']], $database->data(['table' => 'audit']));
    }

    public function testDropDatabaseReplacesAllTablesAndTakesPrecedenceOverTheOtherDropOptions(): void
    {
        $database = $this->database();
        $this->assertSame(1, $database->load(['replacement' => [['value' => 'New']]], ['dropDatabase' => true, 'dropTables' => true, 'dropData' => true]));
        $this->assertSame([['value' => 'New']], $database->data(['table' => 'replacement']));
        $this->assertFalse($database->schema()->hasTable('products'));
        $this->assertFalse($database->schema()->hasTable('audit'));
        $this->assertSame(0, $database->load([], ['dropDatabase' => true]));
        $this->assertSame([], $database->schema()->getTables());
    }

    public function testValidationAlsoPrecedesDroppingTheDatabase(): void
    {
        $database = $this->database();
        $before = $database->spreadsheet()->toArray();
        try {
            $database->load(['valid' => [['id' => 1]], 'invalid' => 'rows'], ['dropDatabase' => true]);
            $this->fail('Invalid input must not drop the existing database.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must be an array', $exception->getMessage());
        }
        $this->assertSame($before, $database->spreadsheet()->toArray());
    }

    public function testInvalidExistingPrimaryKeysAreRejectedWithoutChangingRows(): void
    {
        $database = $this->database();
        $sheet = $database->spreadsheet()->getSheet('products');
        $this->assertNotNull($sheet);
        $sheet->setRows([['id' => 'P1', 'name' => 'One', 'note' => null], ['id' => 'P1', 'name' => 'Two', 'note' => null]]);
        $before = $database->spreadsheet()->toArray();
        try {
            $database->load(['products' => [['id' => 'P3', 'name' => 'New', 'note' => null]]]);
            $this->fail('Ambiguous existing primary keys must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Duplicate primary key in table "products".', $exception->getMessage());
        }
        $this->assertSame($before, $database->spreadsheet()->toArray());
    }
}
