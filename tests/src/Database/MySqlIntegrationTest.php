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
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetLoader;
use Derafu\TestsETL\Fixture\RealDatabaseContainer;
use Doctrine\DBAL\Exception as DoctrineException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
final class MySqlIntegrationTest extends TestCase
{
    private static RealDatabaseContainer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = RealDatabaseContainer::mysql();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$server)) {
            self::$server->stop();
        }
    }

    public function testUpsertsUpdateCompositeKeysAndQuoteReservedIdentifiers(): void
    {
        $database = self::$server->database();
        $database->connection()->executeStatement('CREATE TABLE `order` (tenant INTEGER, `key` VARCHAR(30), name VARCHAR(80) NOT NULL, PRIMARY KEY (tenant, `key`)) ENGINE=InnoDB');
        $this->assertSame(2, $database->load(['order' => [
            ['tenant' => 1, 'key' => 'A', 'name' => 'One'],
            ['name' => 'Two', 'key' => 'A', 'tenant' => 2],
        ]]));
        // MySQL counts a changed existing row twice for ON DUPLICATE KEY UPDATE.
        $this->assertSame(2, $database->load(['order' => [['tenant' => 2, 'key' => 'A', 'name' => 'Updated']]]));
        $this->assertSame([
            ['tenant' => 1, 'key' => 'A', 'name' => 'One'],
            ['tenant' => 2, 'key' => 'A', 'name' => 'Updated'],
        ], $database->connection()->fetchAllAssociative('SELECT * FROM `order` ORDER BY tenant'));
        $this->assertSame(0, $database->connection()->getTransactionNestingLevel());
    }

    public function testAPipelineCanCreateTheSchemaAndLoadANewDatabase(): void
    {
        $source = self::$server->database();
        $source->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(80)) ENGINE=InnoDB');
        $source->connection()->insert('records', ['id' => 1, 'name' => 'Incoming']);
        $target = self::$server->database();
        $result = (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $this->assertSame($source->data(), $target->data());
        $this->assertSame(0, $target->connection()->getTransactionNestingLevel());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function transactionContexts(): iterable
    {
        foreach ([false, true] as $sync) {
            foreach ([false, true] as $callerTransaction) {
                yield ($sync ? 'matching schema' : 'sync disabled') . ($callerTransaction ? ' nested' : ' standalone') => [$sync, $callerTransaction];
            }
        }
    }

    #[DataProvider('transactionContexts')]
    public function testDataOnlyFailuresRollBackAllTablesAndPreserveTheCallersTransaction(bool $sync, bool $callerTransaction): void
    {
        $source = self::$server->database();
        $target = self::$server->database();
        foreach ([$source, $target] as $database) {
            $database->connection()->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY, name VARCHAR(80)) ENGINE=InnoDB');
            $database->connection()->executeStatement('CREATE TABLE z_records (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL) ENGINE=InnoDB');
        }
        $source->connection()->insert('a_records', ['id' => 1, 'name' => 'Incoming']);
        $source->connection()->insert('z_records', ['id' => 1, 'amount' => 10]);
        $connection = $target->connection();
        if ($callerTransaction) {
            $connection->beginTransaction();
        }
        $connection->insert('a_records', ['id' => 900, 'name' => 'Keep']);
        $before = $target->data();
        try {
            (new Pipeline())->extract($source)->transform([
                static function (array $data): array {
                    $data['z_records'] = [['id' => 1, 'amount' => null]];
                    return $data;
                },
            ])->load($target)->execute(['sync' => $sync]);
            $this->fail('A data error must roll back all tables when no DDL is needed.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('cannot be null', $exception->getMessage());
        }
        $this->assertSame($before, $target->data());
        $this->assertSame($callerTransaction ? 1 : 0, $connection->getTransactionNestingLevel());
        if ($callerTransaction) {
            $connection->rollBack();
            $this->assertSame([], $target->data(['table' => 'a_records']));
        }
    }

    public function testDdlChangesAndEarlierTableLoadsRemainAfterALaterFailureAsDocumented(): void
    {
        $source = self::$server->database();
        $source->connection()->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY, name VARCHAR(80)) ENGINE=InnoDB');
        $source->connection()->executeStatement('CREATE TABLE z_records (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL) ENGINE=InnoDB');
        $source->connection()->insert('a_records', ['id' => 1, 'name' => 'Incoming']);
        $source->connection()->insert('z_records', ['id' => 1, 'amount' => 10]);
        $target = self::$server->database();
        $target->connection()->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY) ENGINE=InnoDB');
        $target->connection()->executeStatement('CREATE TABLE obsolete (id INTEGER PRIMARY KEY) ENGINE=InnoDB');
        try {
            (new Pipeline())->extract($source)->transform([
                static function (array $data): array {
                    $data['z_records'] = [['id' => 1, 'amount' => null]];
                    return $data;
                },
            ])->load($target)->execute();
            $this->fail('The later table must fail even though DDL prevents full rollback.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('cannot be null', $exception->getMessage());
        }
        $this->assertTrue($target->schema()->getTable('a_records')?->hasColumn('name'));
        $this->assertFalse($target->schema()->hasTable('obsolete'));
        $this->assertSame([['id' => 1, 'name' => 'Incoming']], $target->data(['table' => 'a_records']));
        $this->assertSame([], $target->data(['table' => 'z_records']));
        $this->assertSame(0, $target->connection()->getTransactionNestingLevel());
    }

    public function testASuccessfulDataOnlyPipelineDoesNotCommitTheCallersTransaction(): void
    {
        $source = self::$server->database();
        $target = self::$server->database();
        foreach ([$source, $target] as $database) {
            $database->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY) ENGINE=InnoDB');
        }
        $source->connection()->insert('records', ['id' => 1]);
        $target->connection()->beginTransaction();
        $result = (new Pipeline())->extract($source)->transform()->load($target)->execute(['sync' => false]);
        $this->assertSame(1, $result->rowsLoaded());
        $this->assertSame([['id' => 1]], $target->data(['table' => 'records']));
        $this->assertSame(1, $target->connection()->getTransactionNestingLevel());
        $target->connection()->rollBack();
        $this->assertSame([], $target->data(['table' => 'records']));
    }

    public function testXlsxRoundTripKeepsCustomSettingsAndAvoidsUnnecessaryDdl(): void
    {
        $source = self::$server->database();
        $source->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci) DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci');
        $source->connection()->insert('records', ['id' => 1, 'name' => 'Original']);
        $dump = (new SpreadsheetDumper())->dumpToString($source->spreadsheet(), 'xlsx');
        $document = (new SpreadsheetLoader())->loadFromString($dump, 'xlsx');
        $spreadsheetSource = new SpreadsheetDatabase($document);
        $target = self::$server->database();
        $result = (new Pipeline())->extract($spreadsheetSource)->transform()->load($target)->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $native = $target->connection()->createSchemaManager()->introspectTable('records');
        $this->assertSame('latin1', $native->getOption('charset'));
        $this->assertSame('latin1_swedish_ci', $native->getOption('collation'));
        $this->assertSame('utf8mb4', $native->getColumn('name')->getCharset());
        $this->assertSame('utf8mb4_unicode_ci', $native->getColumn('name')->getCollation());
        $this->assertSame($source->data(), $target->data());

        // An identical schema must allow the caller to roll back the next load.
        $source->connection()->insert('records', ['id' => 2, 'name' => 'Temporary']);
        $target->connection()->beginTransaction();
        (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $this->assertSame(1, $target->connection()->getTransactionNestingLevel());
        $this->assertCount(2, $target->data(['table' => 'records']));
        $target->connection()->rollBack();
        $this->assertSame([['id' => 1, 'name' => 'Original']], $target->data(['table' => 'records']));
    }

    public function testRealTableDefaultAndColumnChangesAreAppliedAndTheNextSyncHasNoDdl(): void
    {
        $source = self::$server->database();
        $target = self::$server->database();
        $source->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(80)) DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci');
        $target->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(80)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        $target->connection()->insert('records', ['id' => 1, 'name' => 'Keep']);
        $target->sync($source);
        $native = $target->connection()->createSchemaManager()->introspectTable('records');
        $this->assertSame('latin1', $native->getOption('charset'));
        $this->assertSame('latin1_swedish_ci', $native->getOption('collation'));
        $this->assertSame('latin1', $native->getColumn('name')->getCharset());
        $this->assertSame('latin1_swedish_ci', $native->getColumn('name')->getCollation());
        $target->connection()->beginTransaction();
        $target->sync($source);
        $this->assertSame(1, $target->connection()->getTransactionNestingLevel());
        $target->connection()->insert('records', ['id' => 2, 'name' => 'Temporary']);
        $target->connection()->rollBack();
        $this->assertSame([['id' => 1, 'name' => 'Keep']], $target->data(['table' => 'records']));
    }

    public function testManualSchemaMetadataChangesAreUsedWithoutAlteringTheSourceDatabase(): void
    {
        $source = self::$server->database();
        $source->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(80)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        $column = $source->schema()->getTable('records')?->getColumn('name');
        $this->assertNotNull($column);
        $column->setPlatformOptions(['mysql' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']]);
        $target = self::$server->database();
        $target->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(30)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        $target->sync($source);
        $this->assertSame(80, $target->connection()->createSchemaManager()->introspectTable('records')->getColumn('name')->getLength());
        $this->assertSame('utf8mb4_unicode_ci', $target->connection()->createSchemaManager()->introspectTable('records')->getColumn('name')->getCollation());
        $this->assertSame('utf8mb4_0900_ai_ci', $source->connection()->createSchemaManager()->introspectTable('records')->getColumn('name')->getCollation());
    }

    public function testAbsentMysqlOptionsPreserveTheExistingDestinationConfiguration(): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(80) COLLATE NOCASE)');
        $source->connection()->insert('records', ['id' => 1, 'name' => 'Temporary']);
        $target = self::$server->database();
        $target->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci) DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci');
        $target->connection()->beginTransaction();
        (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $this->assertSame(1, $target->connection()->getTransactionNestingLevel());
        $native = $target->connection()->createSchemaManager()->introspectTable('records');
        $this->assertSame('latin1', $native->getOption('charset'));
        $this->assertSame('utf8mb4_unicode_ci', $native->getColumn('name')->getCollation());
        $target->connection()->rollBack();
        $this->assertSame([], $target->data(['table' => 'records']));
    }
}
