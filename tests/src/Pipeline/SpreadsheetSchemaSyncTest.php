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
use Derafu\ETL\Schema\ForeignKey;
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
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
#[CoversClass(ForeignKey::class)]
#[CoversClass(PipelineException::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class SpreadsheetSchemaSyncTest extends TestCase
{
    private function source(): DoctrineDatabase
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection = $source->connection();
        $connection->executeStatement('CREATE TABLE categories (code TEXT PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE products (
            id INTEGER,
            sku TEXT PRIMARY KEY,
            price INTEGER NOT NULL,
            name VARCHAR(80) DEFAULT \'Unknown\',
            category TEXT,
            CONSTRAINT products_category FOREIGN KEY (category) REFERENCES categories (code) ON DELETE CASCADE
        )');
        $connection->executeStatement('CREATE INDEX products_price ON products (price)');
        $connection->executeStatement('CREATE TABLE empty_records (id INTEGER, note TEXT)');
        $connection->insert('categories', ['code' => 'C1']);
        $connection->insert('products', ['id' => 1, 'sku' => 'S1', 'price' => 10, 'category' => 'C1']);
        $connection->insert('products', ['id' => 1, 'sku' => 'S2', 'price' => 20, 'category' => 'C1']);
        return $source;
    }

    public function testSyncPreservesTheSourceDefinitionsWithoutLoadingRowsOrRemovingUnrelatedTables(): void
    {
        $source = $this->source();
        $sourceBefore = $source->data();
        $document = new Spreadsheet();
        $document->createSheet('audit', [['message' => 'Keep']], true);
        $target = new SpreadsheetDatabase($document);
        $cached = $target->schema();
        $this->assertSame($target, $target->sync($source));
        $this->assertNotSame($cached, $target->schema());
        foreach ($source->schema()->getTables() as $table) {
            $this->assertEqualsCanonicalizing($table, $target->schema()->getTable($table->getName()));
            $this->assertSame([], $target->data(['table' => $table->getName()]));
        }
        $this->assertSame([['message' => 'Keep']], $target->data(['table' => 'audit']));
        $this->assertSame($sourceBefore, $source->data());
        $this->assertTrue($target->spreadsheet()->hasSheet('__schema'));
    }

    public function testThePipelineKeepsExplicitKeysTypesDefaultsIndexesForeignKeysAndEmptyTables(): void
    {
        $source = $this->source();
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $pipeline = (new Pipeline())->extract($source)->transform()->load($target);
        $counts = [$pipeline->execute()->rowsLoaded()];
        foreach ($source->schema()->getTables() as $table) {
            $this->assertEqualsCanonicalizing($table, $target->schema()->getTable($table->getName()));
        }
        $this->assertSame($source->data(), $target->data());
        $products = $target->schema()->getTable('products');
        $this->assertNotNull($products);
        $this->assertSame(['sku'], $products->getPrimaryKey());
        $price = $products->getColumn('price');
        $this->assertNotNull($price);
        $this->assertSame('integer', $price->getType());
        $this->assertFalse($price->isNullable());
        $name = $products->getColumn('name');
        $this->assertNotNull($name);
        $this->assertSame('Unknown', $name->getDefault());
        $this->assertSame(80, $name->getLength());
        $this->assertTrue($products->hasIndex('products_price'));
        $this->assertCount(1, $products->getForeignKeys());
        $empty = $target->schema()->getTable('empty_records');
        $this->assertNotNull($empty);
        $this->assertSame([], $empty->getPrimaryKey());
        $this->assertTrue($empty->hasColumn('note'));
        $this->assertSame([], $target->data(['table' => 'empty_records']));

        // Both products have the same id: updates must use the explicit sku key.
        $source->connection()->update('products', ['id' => 2, 'price' => 25], ['sku' => 'S2']);
        $counts[] = $pipeline->execute()->rowsLoaded();
        $this->assertSame([3, 3], $counts);
        $this->assertSame($source->data(), $target->data());
        $this->assertCount(2, $target->data(['table' => 'products']));
    }

    public function testALaterFailureRestoresTheDocumentAndSchemaFromBeforeSynchronization(): void
    {
        $source = $this->source();
        $original = new Spreadsheet();
        $original->createSheet('audit', [['message' => 'Keep']], true);
        $target = new SpreadsheetDatabase($original);
        $before = $original->toArray();
        $cached = $target->schema();
        $sourceBefore = $source->data();
        try {
            (new Pipeline())->extract($source)->transform([
                static function (array $data): array {
                    $data['products'][1]['sku'] = null;
                    return $data;
                },
            ])->load($target)->execute();
            $this->fail('An invalid explicit key must abort schema and data changes.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('sku', $exception->getMessage());
        }
        $this->assertSame($original, $target->spreadsheet());
        $this->assertSame($before, $target->spreadsheet()->toArray());
        $this->assertEqualsCanonicalizing($cached, $target->schema());
        $this->assertSame($sourceBefore, $source->data());
    }

    public function testIncompatibleDefinitionsAreRejectedBeforeApplyingAnySchemaChanges(): void
    {
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load(['a_new' => [['id' => 'N1']], 'products' => [['sku' => 'S1', 'price' => 10]]]);
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $target->load(['products' => [['id' => 'P1', 'name' => 'Keep']]]);
        $before = $target->spreadsheet()->toArray();
        try {
            $target->sync($source);
            $this->fail('Incompatible tables require an explicit recreation option.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('incompatible', $exception->getMessage());
        }
        $this->assertSame($before, $target->spreadsheet()->toArray());
        $this->assertFalse($target->schema()->hasTable('a_new'));
        $target->sync($source, ['dropTables' => true]);
        $this->assertEqualsCanonicalizing($source->schema()->getTable('products'), $target->schema()->getTable('products'));
        $this->assertSame([], $target->data(['table' => 'products']));
    }

    /** @return iterable<string, array{array<string, bool>, bool}> */
    public static function replacementOptions(): iterable
    {
        yield 'drop data' => [['dropData' => true], true];
        yield 'drop tables' => [['dropTables' => true], true];
        yield 'drop database' => [['dropDatabase' => true], false];
    }

    /** @param array<string, bool> $options */
    #[DataProvider('replacementOptions')]
    public function testSyncUsesTheAgreedDropPoliciesWithoutLoadingSourceRows(array $options, bool $keepAudit): void
    {
        $source = new SpreadsheetDatabase(new Spreadsheet());
        $source->load(['products' => [['id' => 'P1']]]);
        $target = new SpreadsheetDatabase(new Spreadsheet());
        $target->load(['products' => [['id' => 'P2']], 'audit' => [['message' => 'Keep']]]);
        $target->sync($source, $options);
        $this->assertSame([], $target->data(['table' => 'products']));
        $this->assertSame($keepAudit, $target->schema()->hasTable('audit'));
        $this->assertSame($keepAudit, $target->spreadsheet()->hasSheet('audit'));
        $this->assertSame([['id' => 'P1']], $source->data(['table' => 'products']));
    }
}
