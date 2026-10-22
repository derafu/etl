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
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\ETL\Transform\DataRules;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\TestsETL\Fixture\RealDatabaseContainer;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
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
#[CoversClass(Index::class)]
#[CoversClass(PipelineException::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class PostgreSqlIntegrationTest extends TestCase
{
    private static RealDatabaseContainer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = RealDatabaseContainer::postgresql();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$server)) {
            self::$server->stop();
        }
    }

    public function testUpsertsUseTheCompositePrimaryKeyAndQuoteReservedIdentifiers(): void
    {
        $database = self::$server->database();
        $database->connection()->executeStatement('CREATE TABLE "order" (tenant INTEGER, "key" TEXT, name TEXT NOT NULL, PRIMARY KEY (tenant, "key"))');
        $this->assertSame(2, $database->load(['order' => [
            ['tenant' => 1, 'key' => 'A', 'name' => 'One'],
            ['name' => 'Two', 'key' => 'A', 'tenant' => 2],
        ]]));
        $this->assertSame(1, $database->load(['order' => [['tenant' => 2, 'key' => 'A', 'name' => 'Updated']]]));
        $this->assertSame([
            ['tenant' => 1, 'key' => 'A', 'name' => 'One'],
            ['tenant' => 2, 'key' => 'A', 'name' => 'Updated'],
        ], $database->connection()->fetchAllAssociative('SELECT * FROM "order" ORDER BY tenant'));
        $this->assertSame(0, $database->connection()->getTransactionNestingLevel());
    }

    public function testUpsertsWithoutAPrimaryKeyFailWithoutWritingRows(): void
    {
        $database = self::$server->database();
        $database->connection()->executeStatement('CREATE TABLE logs (message TEXT)');
        try {
            $database->load(['logs' => [['message' => 'Do not insert']]]);
            $this->fail('PostgreSQL upserts require an explicit primary key.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Table "logs" must have a primary key for PostgreSQL upserts.', $exception->getMessage());
        }
        $this->assertSame([], $database->data(['table' => 'logs']));
        $this->assertSame(0, $database->connection()->getTransactionNestingLevel());
    }

    public function testAnEmptyTableNameFailsBeforeIntrospection(): void
    {
        $database = self::$server->database();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PostgreSQL table names must not be empty.');
        $database->load(['' => [['id' => 1]]]);
    }

    public function testSyncAndDatabaseLoadsPreserveRowsDefaultsAndIndexes(): void
    {
        $source = self::$server->database();
        $source->connection()->executeStatement("CREATE TABLE products (id INTEGER PRIMARY KEY, name VARCHAR(80) DEFAULT 'Unknown')");
        $source->connection()->executeStatement('CREATE INDEX products_name ON products (name)');
        $source->connection()->executeStatement('INSERT INTO products (id) VALUES (1)');
        $source->connection()->executeStatement('CREATE TABLE empty_records (id INTEGER PRIMARY KEY)');
        $target = self::$server->database();
        $target->connection()->executeStatement('CREATE TABLE obsolete (id INTEGER PRIMARY KEY)');
        $target->sync($source);
        $this->assertSame(1, $target->load($source));
        $this->assertSame($source->data(), $target->data());
        $this->assertSame(['empty_records', 'products'], $target->connection()->createSchemaManager()->listTableNames());
        $table = $target->schema()->getTable('products');
        $this->assertNotNull($table);
        $this->assertSame(['id'], $table->getPrimaryKey());
        $this->assertSame('Unknown', $table->getColumn('name')?->getDefault());
        $this->assertTrue($table->hasIndex('products_name'));
    }

    /** @return iterable<string, array{bool}> */
    public static function transactionContexts(): iterable
    {
        yield 'standalone' => [false];
        yield 'inside caller transaction' => [true];
    }

    #[DataProvider('transactionContexts')]
    public function testALaterFailureRollsBackSchemaAndEarlierData(bool $callerTransaction): void
    {
        $source = self::$server->database();
        $source->connection()->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $source->connection()->executeStatement('CREATE TABLE z_records (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
        $source->connection()->insert('a_records', ['id' => 1, 'name' => 'Incoming']);
        $source->connection()->insert('z_records', ['id' => 1, 'amount' => 10]);
        $target = self::$server->database();
        $connection = $target->connection();
        $connection->executeStatement('CREATE TABLE a_records (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE z_records (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE obsolete (id INTEGER PRIMARY KEY)');
        if ($callerTransaction) {
            $connection->beginTransaction();
        }
        $connection->insert('a_records', ['id' => 900]);
        $before = $target->data();
        try {
            (new Pipeline())->extract($source)->transform([
                static function (array $data): array {
                    $data['z_records'] = [['id' => 1, 'amount' => null]];
                    return $data;
                },
            ])->load($target)->execute();
            $this->fail('A NOT NULL violation must roll back the complete migration.');
        } catch (DoctrineException $exception) {
            $this->assertStringContainsString('not-null', $exception->getMessage());
        }
        $this->assertSame($before, $target->data());
        $this->assertTrue($target->schema()->hasTable('obsolete'));
        $this->assertFalse($target->schema()->getTable('a_records')?->hasColumn('name'));
        $this->assertSame($callerTransaction ? 1 : 0, $connection->getTransactionNestingLevel());
        if ($callerTransaction) {
            $connection->rollBack();
            $this->assertSame([], $target->data(['table' => 'a_records']));
        }
    }

    public function testASuccessfulPipelineDoesNotCommitTheCallersTransaction(): void
    {
        $source = self::$server->database();
        $source->connection()->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $source->connection()->insert('records', ['id' => 1]);
        $target = self::$server->database();
        $target->connection()->beginTransaction();
        $result = (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $this->assertSame([['id' => 1]], $target->data(['table' => 'records']));
        $this->assertSame(1, $target->connection()->getTransactionNestingLevel());
        $target->connection()->rollBack();
        $this->assertSame([], $target->connection()->createSchemaManager()->listTableNames());
    }

    public function testPostgresqlUsesItsOwnCollationAndIgnoresMysqlMetadata(): void
    {
        $column = (new Column('name', 'string'))->setLength(80)->setPlatformOptions([
            'mysql' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            'postgresql' => ['collation' => 'C'],
        ]);
        $table = (new Table('records'))->addColumn(new Column('id', 'integer'))->addColumn($column)->setPrimaryKey(['id']);
        $document = (new SpreadsheetSchemaTarget())->applySchema((new Schema())->addTable($table));
        $source = new SpreadsheetDatabase($document);
        $source->load(['records' => [['id' => 1, 'name' => 'Original']]]);
        $target = self::$server->database();
        (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $native = $target->connection()->createSchemaManager()->introspectTable('records')->getColumn('name');
        $this->assertSame('C', $native->getCollation());
        $this->assertNull($native->getCharset());
        $this->assertSame($source->data(), $target->data());
        $this->assertSame(['postgresql' => ['collation' => 'C']], $target->schema()->getTable('records')?->getColumn('name')?->getPlatformOptions());
    }
}
