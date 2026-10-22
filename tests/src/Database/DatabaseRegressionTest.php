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
use Derafu\TestsETL\Fixture\TemporaryFiles;
use Doctrine\DBAL\DriverManager;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
final class DatabaseRegressionTest extends TestCase
{
    use TemporaryFiles;

    public function testDoctrineExportPreservesRowsEmptyTablesAndTheSchemaWithoutRecursion(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, name VARCHAR(80) DEFAULT \'Unknown\')');
        $connection->executeStatement('CREATE TABLE empty_records (id INTEGER PRIMARY KEY, note TEXT)');
        $connection->executeStatement("INSERT INTO products VALUES (1, 'Laptop')");
        $connection->executeStatement('INSERT INTO products VALUES (2, NULL)');
        $source = new DoctrineDatabase($connection);
        $spreadsheet = $source->spreadsheet();
        $database = new SpreadsheetDatabase($spreadsheet);
        $this->assertSame([['id' => 1, 'name' => 'Laptop'], ['id' => 2, 'name' => null]], $database->data(['table' => 'products']));
        $this->assertSame([], $database->data(['table' => 'empty_records']));
        $products = $database->schema()->getTable('products');
        $this->assertNotNull($products);
        $this->assertSame(['id'], $products->getPrimaryKey());
        $name = $products->getColumn('name');
        $this->assertNotNull($name);
        $this->assertSame('Unknown', $name->getDefault());
        $empty = $database->schema()->getTable('empty_records');
        $this->assertNotNull($empty);
        $this->assertTrue($empty->hasColumn('note'));
        $this->assertSame(0, $connection->getTransactionNestingLevel());
        $spreadsheet->createSheet('notes', [['message' => 'Not a declared business table']], true);
        $this->assertSame([
            'empty_records' => [],
            'products' => [['id' => 1, 'name' => 'Laptop'], ['id' => 2, 'name' => null]],
        ], $database->data());
        $this->assertSame($database->data(), $database->data(['table' => null]));
        $this->assertTrue($database->spreadsheet()->hasSheet('__schema'));
        $this->assertTrue($database->spreadsheet()->hasSheet('notes'));
        $this->assertSame(
            [['message' => 'Not a declared business table']],
            $database->data(['table' => 'notes'])
        );
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target->sync($database);
        $this->assertSame(2, $target->load($database));
        $this->assertSame($source->data(), $target->data());
        $this->assertSame(['empty_records', 'products'], $target->connection()->createSchemaManager()->listTableNames());
        $this->assertSame(0, $target->connection()->getTransactionNestingLevel());
    }

    public function testADeclaredSqlTableNamedSchemaIsLoadedAsABusinessTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE __schema (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement("INSERT INTO __schema VALUES (1, 'Business data')");
        $source = new DoctrineDatabase($connection);
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target->sync($source);
        $this->assertSame(1, $target->load($source));
        $this->assertSame([['id' => 1, 'name' => 'Business data']], $target->data(['table' => '__schema']));
        $this->assertSame(0, $target->connection()->getTransactionNestingLevel());
    }

    /** @return iterable<string, array{string}> */
    public static function documentFormats(): iterable
    {
        yield 'json' => ['json'];
    }

    #[DataProvider('documentFormats')]
    public function testNewDocumentsAreValidAndCanBePopulatedSavedAndReopened(string $format): void
    {
        $file = $this->directory . '/nested/documents/products.' . $format;
        $manager = new DatabaseManager();
        $database = $manager->connect($file, ['createIfNotExists' => true]);
        $this->assertFileExists($file);
        $this->assertSame([], $database->data());
        if ($format === 'json') {
            $content = file_get_contents($file);
            $this->assertNotFalse($content);
            $this->assertSame([], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
        }
        $this->assertSame(1, $database->load(['products' => [['id' => 'P001', 'name' => 'Laptop']]]));
        $database->save($file);
        $reopened = $manager->connect($file, ['readOnly' => true]);
        $this->assertSame([['id' => 'P001', 'name' => 'Laptop']], $reopened->data(['table' => 'products']));
    }

    public function testDropDataExposesNoHeadersAsDataAndPreservesTheSchema(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet('products', [['id' => 'P001', 'name' => 'Laptop']], true);
        $source = new SpreadsheetDatabase($spreadsheet);
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $this->assertSame(1, $target->load($source, ['dropData' => true]));
        $this->assertSame([['id' => 'P001', 'name' => 'Laptop']], $target->data(['table' => 'products']));
        $table = $target->schema()->getTable('products');
        $this->assertNotNull($table);
        $this->assertTrue($table->hasColumn('id'));
        $this->assertTrue($table->hasColumn('name'));
        $this->assertSame(1, $target->load(['products' => [['id' => 'P002', 'name' => 'Desktop']]], ['dropData' => true]));
        $this->assertSame([['id' => 'P002', 'name' => 'Desktop']], $target->data(['table' => 'products']));
    }

    public function testNewXlsxStartsWithAnEmptySchemaAndCanBeReopenedBeforeAndAfterLoadingTables(): void
    {
        $file = $this->directory . '/nested/documents/products.xlsx';
        $manager = new DatabaseManager();
        $database = $manager->connect($file, ['createIfNotExists' => true]);
        $this->assertFileExists($file);
        $this->assertSame(['__schema'], $database->spreadsheet()->getSheetNames());
        $this->assertSame([], $database->schema()->getTables());

        // Verify the initial file itself is valid, even if no data is loaded.
        $native = IOFactory::load($file);
        $this->assertSame(['__schema'], $native->getSheetNames());
        $this->assertSame(1, $native->getSheetCount());
        $native->disconnectWorksheets();

        $reopened = $manager->connect($file);
        $this->assertSame([], $reopened->schema()->getTables());
        $rows = [['id' => 'P001', 'name' => 'Laptop', 'code' => '0007', 'active' => 'false']];
        $this->assertSame(1, $reopened->load(['products' => $rows]));
        $this->assertSame($file, $reopened->save($file));

        $loaded = $manager->connect($file, ['readOnly' => true]);
        $this->assertSame(['__schema', 'products'], $loaded->spreadsheet()->getSheetNames());
        $this->assertSame($rows, $loaded->data(['table' => 'products']));
        $this->assertSame(['products'], array_keys($loaded->schema()->getTables()));
        $table = $loaded->schema()->getTable('products');
        $this->assertNotNull($table);
        $this->assertTrue($table->hasColumn('code'));
        $this->assertTrue($table->hasColumn('active'));
        $this->assertFalse($loaded->spreadsheet()->hasSheet('Sheet'));
    }
}
