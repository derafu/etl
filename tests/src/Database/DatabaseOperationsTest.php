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
use Derafu\Spreadsheet\Format\CsvPhpSpreadsheetHandler;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetFactory;
use Derafu\TestsETL\Fixture\TemporaryFiles;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException as RuntimeException;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
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
final class DatabaseOperationsTest extends TestCase
{
    use TemporaryFiles;

    public function testSqliteFileCanBeReopenedAndQueried(): void
    {
        $file = $this->directory . '/records.sqlite';
        $database = new DoctrineDatabase($file, ['readOnly' => false]);
        $database->load('CREATE TABLE records (id INTEGER PRIMARY KEY, name TEXT DEFAULT \'Unknown\')');
        $database->load("INSERT INTO records VALUES (1, 'Laptop')");
        $this->assertSame(['readOnly' => false], $database->options());
        $reopened = (new DatabaseManager())->connect(['format' => 'sqlite', 'file' => $file]);
        $this->assertSame(['records' => [['id' => 1, 'name' => 'Laptop']]], $reopened->data());
        $this->assertSame([['id' => 1, 'name' => 'Laptop']], $reopened->data(['table' => 'records']));
        $schema = $reopened->schema();
        $table = $schema->getTable('records');
        $this->assertNotNull($table);
        $this->assertSame(['id'], $table->getPrimaryKey());
        $column = $table->getColumn('name');
        $this->assertNotNull($column);
        $this->assertSame('Unknown', $column->getDefault());
    }

    public function testSyncHandlesEqualSchemasAddedColumnsAndRemovedTables(): void
    {
        $sourceConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sourceConnection->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name TEXT)');
        $source = new DoctrineDatabase($sourceConnection);
        $targetConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $targetConnection->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $targetConnection->executeStatement('CREATE TABLE obsolete (id INTEGER)');
        $target = new DoctrineDatabase($targetConnection);
        $this->assertSame($target, $target->sync($source));
        $this->assertSame(['records'], $targetConnection->createSchemaManager()->listTableNames());
        $this->assertArrayHasKey('name', $targetConnection->createSchemaManager()->listTableColumns('records'));
        $this->assertSame($target, $target->sync($source));
        $this->assertSame(0, $targetConnection->getTransactionNestingLevel());
    }

    public function testSpreadsheetSaveAndReopenPreserveRowsAndFormatOptions(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet('Products', [['id' => 'P001', 'name' => 'Laptop']], true);
        $database = new SpreadsheetDatabase($spreadsheet, ['format' => 'xlsx']);
        $file = $this->directory . '/products.xlsx';
        $this->assertSame($file, $database->save($file));
        $reopened = (new DatabaseManager())->connect($file, ['readOnly' => true]);
        $this->assertSame($database->data(), $reopened->data());
        $this->assertSame($database, $database->sync($reopened));
        $this->assertSame($spreadsheet, $database->spreadsheet());
    }

    public function testExistingCsvFilesCanBeReadAndSavedWithThePhpSpreadsheetAdapter(): void
    {
        $file = $this->directory . '/products.csv';
        file_put_contents($file, "id,name\nP001,Laptop\n");
        $factory = new SpreadsheetFactory(['csv' => CsvPhpSpreadsheetHandler::class]);
        $manager = new DatabaseManager($factory);
        $loaded = $manager->connect($file);
        $this->assertSame([[['id' => 'P001', 'name' => 'Laptop']]], array_values($loaded->data()));
        $this->assertFileExists($file);
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet('Products', [['id' => 'P001', 'name' => 'Laptop']], true);
        $filled = new SpreadsheetDatabase($spreadsheet, dumper: new SpreadsheetDumper($factory));
        $filled->save($file);
        $reopened = $manager->connect($file);
        $rows = array_values($reopened->data());
        $this->assertCount(1, $rows);
        $this->assertSame([['id' => 'P001', 'name' => 'Laptop']], $rows[0]);
    }

    public function testInlineDataWithAnExplicitFormatCanBeConnected(): void
    {
        $database = (new DatabaseManager())->connect('{"Products":[{"id":1}]}', ['format' => 'json']);
        $this->assertSame([['id' => 1]], $database->data(['table' => 'Products']));
    }

    public function testMissingFilesAreRejectedWhenCreationIsDisabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File does not exist and creation is disabled');
        (new DatabaseManager())->connect($this->directory . '/missing.xlsx');
    }

    public function testEmptyConnectionConfigurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The source is not valid for creating a connection');
        (new DatabaseManager())->connect([]);
    }

    public function testDroppingSpreadsheetDataLoadsTheSourceRowsAndKeepsItsSchema(): void
    {
        $source = new Spreadsheet();
        $source->createSheet('Products', [['id' => 1, 'name' => 'Laptop']], true);
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $this->assertSame(1, $target->load(new SpreadsheetDatabase($source), ['dropData' => true, 'schemaSheetName' => 'Structure']));
        $this->assertTrue($target->spreadsheet()->hasSheet('Structure'));
        $sheet = $target->spreadsheet()->getSheet('Products');
        $this->assertNotNull($sheet);
        $this->assertSame([['id' => 1, 'name' => 'Laptop']], $sheet->getDataRows());
        $schema = (new SpreadsheetSchemaSource('Structure'))->extractSchema($target->spreadsheet());
        $table = $schema->getTable('Products');
        $this->assertNotNull($table);
        $this->assertTrue($table->hasColumn('name'));
    }

    public function testSyncingEmptyDatabasesDoesNotCreateTablesOrLeaveAnOpenTransaction(): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->assertSame($target, $target->sync($source));
        $this->assertSame([], $target->data());
        $this->assertSame(0, $target->connection()->getTransactionNestingLevel());
    }

    public function testSavingAnUnsupportedDoctrineDumpFailsWithoutOverwritingTheDestination(): void
    {
        $file = $this->directory . '/existing.sql';
        file_put_contents($file, 'Keep the existing backup.');
        $database = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $database->save($file);
            $this->fail('An unsupported dump must fail explicitly.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Dump from Doctrine DBAL not implemented.', $exception->getMessage());
        }
        $this->assertSame('Keep the existing backup.', file_get_contents($file));
    }

    public function testReadOnlyFilesCanBeReadButAreRejectedForWriting(): void
    {
        $file = $this->directory . '/read-only.json';
        file_put_contents($file, '{"Products":[{"id":"P001"}]}');
        chmod($file, 0400);
        $manager = new DatabaseManager();
        $this->assertSame([['id' => 'P001']], $manager->connect($file, ['readOnly' => true])->data(['table' => 'Products']));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File is not writable: ' . $file);
        $manager->connect($file);
    }

    public function testUnreadableFilesAreRejectedBeforeParsing(): void
    {
        $file = $this->directory . '/unreadable.json';
        file_put_contents($file, '{}');
        chmod($file, 0200);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File is not readable: ' . $file);
        (new DatabaseManager())->connect($file);
    }

    public function testFilesCannotBeCreatedInAReadOnlyDirectory(): void
    {
        $directory = $this->directory . '/read-only';
        mkdir($directory);
        chmod($directory, 0500);
        try {
            (new DatabaseManager())->connect($directory . '/new.json', ['createIfNotExists' => true]);
            $this->fail('Creating a file in a read-only directory must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('is not writable for creating an empty file', $exception->getMessage());
            $this->assertFileDoesNotExist($directory . '/new.json');
        } finally {
            chmod($directory, 0700);
        }
    }

    public function testAFileBlockingDirectoryCreationLeavesTheExistingFileUntouched(): void
    {
        $blockingFile = $this->directory . '/blocked';
        file_put_contents($blockingFile, 'Keep the existing file.');
        $target = $blockingFile . '/nested/products.json';
        $warning = null;

        // mkdir() emits a native warning before the manager throws its own
        // exception. Capture only that expected warning from the real failure.
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if ($severity !== E_WARNING || !str_starts_with($message, 'mkdir():')) {
                return false;
            }
            $warning = $message;
            return true;
        }, E_WARNING);
        try {
            (new DatabaseManager())->connect($target, ['createIfNotExists' => true]);
            $this->fail('A regular file cannot be used as a parent directory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Target directory does not exist and cannot be created: ' . $target,
                $exception->getMessage()
            );
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($warning);
        $this->assertSame('Keep the existing file.', file_get_contents($blockingFile));
        $this->assertFileDoesNotExist($target);
        $this->assertDirectoryDoesNotExist($blockingFile . '/nested');
    }
}
