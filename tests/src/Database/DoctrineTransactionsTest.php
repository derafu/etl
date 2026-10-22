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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DoctrineException;
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
final class DoctrineTransactionsTest extends TestCase
{
    private function target(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE z_logs (id INTEGER CHECK (id > 0))');
        return $connection;
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function loadingContexts(): iterable
    {
        foreach ([false, true] as $databaseSource) {
            foreach ([false, true] as $outerTransaction) {
                yield ($databaseSource ? 'database' : 'array') . ($outerTransaction ? ' inside caller transaction' : ' standalone') => [$databaseSource, $outerTransaction];
            }
        }
    }

    #[DataProvider('loadingContexts')]
    public function testSqlErrorsRollBackEveryLoadedTableWithoutClosingTheCallerTransaction(bool $databaseSource, bool $outerTransaction): void
    {
        $connection = $this->target();
        if ($outerTransaction) {
            $connection->beginTransaction();
            $connection->executeStatement('INSERT INTO a_records VALUES (900)');
        }
        $source = ['a_records' => [['id' => 1]], 'z_logs' => [['id' => -1]]];
        if ($databaseSource) {
            $sourceConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            $sourceConnection->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY)');
            $sourceConnection->executeStatement('CREATE TABLE z_logs (id INTEGER)');
            $sourceConnection->executeStatement('INSERT INTO a_records VALUES (1)');
            $sourceConnection->executeStatement('INSERT INTO z_logs VALUES (-1)');
            $source = new DoctrineDatabase($sourceConnection);
        }
        try {
            (new DoctrineDatabase($connection))->load($source);
            $this->fail('A real CHECK constraint failure must abort the load.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('CHECK constraint failed', $exception->getMessage());
        }
        $expected = $outerTransaction ? [['id' => 900]] : [];
        $this->assertSame($outerTransaction ? 1 : 0, $connection->getTransactionNestingLevel());
        $this->assertSame($expected, $connection->fetchAllAssociative('SELECT * FROM a_records'));
        $this->assertSame([], $connection->fetchAllAssociative('SELECT * FROM z_logs'));
        if ($outerTransaction) {
            $connection->commit();
        }
        $this->assertSame(0, $connection->getTransactionNestingLevel());
        $this->assertSame($expected, $connection->fetchAllAssociative('SELECT * FROM a_records'));
    }

    public function testSourceReadErrorsAlsoRestoreTheCallerTransaction(): void
    {
        $sourceConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sourceConnection->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY)');
        $source = new DoctrineDatabase($sourceConnection);
        $source->schema();
        // A table removed after introspection represents a real source read failure.
        $sourceConnection->executeStatement('DROP TABLE a_records');
        $target = $this->target();
        $target->beginTransaction();
        $target->executeStatement('INSERT INTO a_records VALUES (900)');
        try {
            (new DoctrineDatabase($target))->load($source);
            $this->fail('Reading the removed source table must fail.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('no such table', $exception->getMessage());
        }
        $this->assertSame(1, $target->getTransactionNestingLevel());
        $this->assertSame([['id' => 900]], $target->fetchAllAssociative('SELECT * FROM a_records'));
        $target->commit();
        $this->assertSame(0, $target->getTransactionNestingLevel());
    }

    /** @return iterable<string, array{bool}> */
    public static function synchronizationContexts(): iterable
    {
        yield 'standalone' => [false];
        yield 'inside caller transaction' => [true];
    }

    #[DataProvider('synchronizationContexts')]
    public function testFailedSchemaSynchronizationRestoresTheOriginalSchemaAndData(bool $outerTransaction): void
    {
        $sourceConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sourceConnection->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, name VARCHAR(80) NOT NULL)');
        $targetConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $targetConnection->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $targetConnection->executeStatement('INSERT INTO products VALUES (1, NULL)');
        if ($outerTransaction) {
            $targetConnection->beginTransaction();
            $targetConnection->executeStatement("INSERT INTO products VALUES (900, 'Caller row')");
        }
        $before = $targetConnection->fetchAllAssociative('SELECT * FROM products ORDER BY id');
        try {
            (new DoctrineDatabase($targetConnection))->sync(new DoctrineDatabase($sourceConnection));
            $this->fail('The NOT NULL constraint must reject existing null data during synchronization.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('NOT NULL constraint failed', $exception->getMessage());
        }
        $this->assertSame($outerTransaction ? 1 : 0, $targetConnection->getTransactionNestingLevel());
        $this->assertSame($before, $targetConnection->fetchAllAssociative('SELECT * FROM products ORDER BY id'));
        $this->assertSame(['products'], $targetConnection->createSchemaManager()->listTableNames());
        $columns = $targetConnection->createSchemaManager()->listTableColumns('products');
        $this->assertFalse($columns['name']->getNotnull());
        if ($outerTransaction) {
            $targetConnection->commit();
        }
        $this->assertSame(0, $targetConnection->getTransactionNestingLevel());
    }

    public function testSuccessfulOperationsLeaveTheCallerTransactionOpenForItsOwnRollback(): void
    {
        $sourceConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sourceConnection->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY)');
        $sourceConnection->executeStatement('INSERT INTO a_records VALUES (1)');
        $source = new DoctrineDatabase($sourceConnection);
        $targetConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target = new DoctrineDatabase($targetConnection);
        $target->sync($source);
        $targetConnection->beginTransaction();
        $this->assertSame($target, $target->sync($source));
        $this->assertSame(1, $targetConnection->getTransactionNestingLevel());
        $this->assertSame(1, $target->load($source));
        $this->assertSame(1, $targetConnection->getTransactionNestingLevel());
        $this->assertSame([['id' => 1]], $targetConnection->fetchAllAssociative('SELECT * FROM a_records'));
        $targetConnection->rollBack();
        $this->assertSame([], $targetConnection->fetchAllAssociative('SELECT * FROM a_records'));
    }
}
