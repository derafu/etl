<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Pipeline;

use Derafu\ETL\Command\PipelineCommand;
use Derafu\ETL\Database\Abstract\AbstractDatabase;
use Derafu\ETL\Database\DatabaseManager;
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Extract\DataExtractor;
use Derafu\ETL\Extract\DataSource;
use Derafu\ETL\Load\DataLoader;
use Derafu\ETL\Load\DataTarget;
use Derafu\ETL\Pipeline\Exception\PipelineException;
use Derafu\ETL\Pipeline\Pipeline;
use Derafu\ETL\Pipeline\PipelineResult;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\ETL\Transform\DataRules;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Doctrine\DBAL\Exception as DoctrineException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PipelineCommand::class)]
#[CoversClass(AbstractDatabase::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(SpreadsheetDatabase::class)]
#[CoversClass(DataExtractor::class)]
#[CoversClass(DataSource::class)]
#[CoversClass(DataLoader::class)]
#[CoversClass(DataTarget::class)]
#[CoversClass(Pipeline::class)]
#[CoversClass(PipelineResult::class)]
#[CoversClass(DataRules::class)]
#[CoversClass(DataTransformer::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(PipelineException::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class PipelineAtomicityTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function sqlContexts(): iterable
    {
        foreach ([false, true] as $sync) {
            foreach ([false, true] as $callerTransaction) {
                yield ($sync ? 'schema and data' : 'data only') . ($callerTransaction ? ' nested' : ' standalone') => [$sync, $callerTransaction];
            }
        }
    }

    #[DataProvider('sqlContexts')]
    public function testFailureInALaterTableRollsBackEarlierRowsAndSchemaChanges(bool $sync, bool $callerTransaction): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->connection()->executeStatement('CREATE TABLE a_products (id INTEGER PRIMARY KEY, name TEXT)');
        $source->connection()->executeStatement('CREATE TABLE z_payments (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
        $source->connection()->executeStatement("INSERT INTO a_products VALUES (1, 'Incoming')");
        $source->connection()->executeStatement('INSERT INTO z_payments VALUES (1, 10)');
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection = $target->connection();
        $connection->executeStatement($sync
            ? 'CREATE TABLE a_products (id INTEGER PRIMARY KEY)'
            : 'CREATE TABLE a_products (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('CREATE TABLE z_payments (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE obsolete (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO obsolete VALUES (5)');
        if ($callerTransaction) {
            $connection->beginTransaction();
        }
        $connection->executeStatement('INSERT INTO a_products (id) VALUES (900)');
        $before = $target->data();
        $target->schema();
        $pipeline = (new Pipeline())->extract($source)->transform([
            static function (array $data): array {
                $data['z_payments'] = [['id' => 1, 'amount' => null]];
                return $data;
            },
        ])->load($target);
        try {
            $pipeline->execute(['sync' => $sync]);
            $this->fail('The SQL constraint must abort the complete load.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('NOT NULL', $exception->getMessage());
        }
        $this->assertSame($before, $target->data());
        $this->assertSame($callerTransaction ? 1 : 0, $connection->getTransactionNestingLevel());
        $this->assertTrue($target->schema()->hasTable('obsolete'));
        $this->assertSame(!$sync, $target->schema()->getTable('a_products')?->hasColumn('name'));
        if ($callerTransaction) {
            $connection->rollBack();
            $this->assertSame([], $target->data(['table' => 'a_products']));
        }
    }

    public function testFailureDuringSchemaSynchronizationRollsBackEarlierDdl(): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->connection()->executeStatement('CREATE TABLE a_new (id INTEGER PRIMARY KEY)');
        $source->connection()->executeStatement('CREATE TABLE z_records (id INTEGER PRIMARY KEY, required TEXT NOT NULL)');
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target->connection()->executeStatement('CREATE TABLE z_records (id INTEGER PRIMARY KEY)');
        $target->connection()->executeStatement('INSERT INTO z_records VALUES (1)');
        try {
            (new Pipeline())->extract($source)->transform()->load($target)->execute();
            $this->fail('Adding a required column without a default must fail for existing rows.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('NOT NULL', $exception->getMessage());
        }
        $this->assertSame(['z_records'], $target->connection()->createSchemaManager()->listTableNames());
        $this->assertSame([['id' => 1]], $target->data(['table' => 'z_records']));
        $this->assertFalse($target->schema()->getTable('z_records')?->hasColumn('required'));
        $this->assertSame(0, $target->connection()->getTransactionNestingLevel());
    }

    public function testSuccessfulPipelineLeavesTheCallersTransactionOpenAndReversible(): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->connection()->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $source->connection()->executeStatement('INSERT INTO products VALUES (1)');
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target->connection()->executeStatement('CREATE TABLE audit (id INTEGER PRIMARY KEY)');
        $target->connection()->beginTransaction();
        $target->connection()->executeStatement('INSERT INTO audit VALUES (900)');
        $result = (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $this->assertSame(1, $target->connection()->getTransactionNestingLevel());
        $this->assertSame([['id' => 1]], $target->data(['table' => 'products']));
        $target->connection()->rollBack();
        $this->assertSame(['audit'], $target->connection()->createSchemaManager()->listTableNames());
        $this->assertSame([], $target->data(['table' => 'audit']));
    }

    public function testRolledBackSchemaIntrospectionDoesNotRemainCached(): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->connection()->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $failure = new RuntimeException('Abort after inspecting the staged schema.');
        try {
            $target->transactional(function () use ($source, $target, $failure): void {
                $target->sync($source);
                $this->assertTrue($target->schema()->hasTable('products'));
                throw $failure;
            }, $source);
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame([], $target->schema()->getTables());
        $this->assertSame([], $target->data());
    }

    public function testALaterSpreadsheetFailurePreservesTheEntireOriginalDocument(): void
    {
        $document = new Spreadsheet();
        $document->createSheet('a_products', [['id' => 'P1', 'name' => 'Original']], true);
        $document->createSheet('audit', [['message' => 'Keep']], true);
        $document->setActiveSheet('audit');
        $target = new SpreadsheetDatabase($document);
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load([
            'a_products' => [['id' => 'P1', 'name' => 'Updated']],
            'b_new' => [['id' => 'N1']],
            'z_payments' => [['id' => 'Z1']],
        ]);
        $before = $document->toArray();
        $sourceBefore = $source->spreadsheet()->toArray();
        try {
            (new Pipeline())->extract($source)->transform([
                static function (array $data): array {
                    $data['z_payments'] = [['id' => null]];
                    return $data;
                },
            ])->load($target)->execute();
            $this->fail('A null primary key must abort every staged table.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must be present and non-null', $exception->getMessage());
        }
        $this->assertSame($document, $target->spreadsheet());
        $this->assertSame($before, $target->spreadsheet()->toArray());
        $this->assertSame('audit', $target->spreadsheet()->getActiveSheet()->getName());
        $this->assertSame(['a_products', 'audit'], array_keys($target->schema()->getTables()));
        $this->assertSame($sourceBefore, $source->spreadsheet()->toArray());
    }

    public function testASuccessfulSpreadsheetPipelinePublishesACopyAndPreservesTheSource(): void
    {
        $document = new Spreadsheet();
        $document->createSheet('audit', [['message' => 'Keep']], true);
        $target = new SpreadsheetDatabase($document);
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load(['products' => [['id' => 'P1']]]);
        $sourceBefore = $source->spreadsheet()->toArray();
        $result = (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $this->assertSame($target, $result->target()->database());
        $this->assertNotSame($document, $target->spreadsheet());
        $this->assertFalse($document->hasSheet('products'));
        $this->assertSame([['id' => 'P1']], $target->data(['table' => 'products']));
        $this->assertSame([['message' => 'Keep']], $target->data(['table' => 'audit']));
        $this->assertSame('audit', $target->spreadsheet()->getActiveSheet()->getName());
        $this->assertSame($sourceBefore, $source->spreadsheet()->toArray());
    }

    public function testAnOuterSpreadsheetTransactionCanAbortASuccessfulPipeline(): void
    {
        $document = new Spreadsheet();
        $document->createSheet('audit', [['message' => 'Keep']], true);
        $target = new SpreadsheetDatabase($document);
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load(['products' => [['id' => 'P1']]]);
        $failure = new RuntimeException('Abort outer operation.');
        try {
            $target->transactional(function () use ($source, $target, $failure): void {
                (new Pipeline())->extract($source)->transform()->load($target)->execute();
                $this->assertTrue($target->schema()->hasTable('products'));
                throw $failure;
            });
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame($document, $target->spreadsheet());
        $this->assertFalse($target->schema()->hasTable('products'));
        $this->assertSame(['audit' => [['message' => 'Keep']]], $target->data());
    }
}
