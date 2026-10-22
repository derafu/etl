<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema;

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
use Derafu\ETL\Schema\Target\SqliteSchemaTarget;
use Derafu\ETL\Transform\DataRules;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetLoader;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PDO;
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
#[CoversClass(SqliteSchemaTarget::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class PlatformTextOptionsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        yield 'xlsx' => ['xlsx'];
        yield 'json' => ['json'];
    }

    private function schema(): Schema
    {
        $column = (new Column('code', 'string'))->setLength(30)->setPlatformOptions([
            'mysql' => ['charset' => 'latin1', 'collation' => 'latin1_swedish_ci'],
            'postgresql' => ['collation' => 'C'],
            'sqlite' => ['collation' => 'NOCASE'],
        ]);
        $table = (new Table('records'))->addColumn($column)->setPrimaryKey(['code'])->setPlatformOptions([
            'mysql' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        ]);
        return (new Schema())->addTable($table);
    }

    #[DataProvider('formats')]
    public function testSpreadsheetRoundTripsPreserveTableAndColumnMetadataWithoutChangingRows(string $format): void
    {
        $schema = $this->schema();
        $document = (new SpreadsheetSchemaTarget())->applySchema($schema);
        $document->createSheet('records', [['code' => 'P0007']], true);
        $dump = (new SpreadsheetDumper())->dumpToString($document, $format);
        $loaded = (new SpreadsheetLoader())->loadFromString($dump, $format);
        $database = new SpreadsheetDatabase($loaded);
        $this->assertSame([['code' => 'P0007']], $database->data(['table' => 'records']));
        $table = $database->schema()->getTable('records');
        $this->assertNotNull($table);
        $this->assertSame($schema->getTable('records')?->getPlatformOptions(), $table->getPlatformOptions());
        $this->assertSame($schema->getTable('records')?->getColumn('code')?->getPlatformOptions(), $table->getColumn('code')?->getPlatformOptions());
    }

    public function testDoctrineTargetsApplyOnlyOptionsForTheirSelectedEngine(): void
    {
        $schema = $this->schema();
        $mysql = (new DoctrineSchemaTarget(new MySQLPlatform()))->applySchema($schema);
        $mysqlColumn = $mysql->getTable('records')->getColumn('code');
        $this->assertSame('latin1', $mysqlColumn->getCharset());
        $this->assertSame('latin1_swedish_ci', $mysqlColumn->getCollation());
        $this->assertSame('utf8mb4_unicode_ci', $mysql->getTable('records')->getOption('collation'));
        $postgresql = (new DoctrineSchemaTarget(new PostgreSQLPlatform()))->applySchema($schema);
        $this->assertNull($postgresql->getTable('records')->getColumn('code')->getCharset());
        $this->assertSame('C', $postgresql->getTable('records')->getColumn('code')->getCollation());
        $sqlite = (new DoctrineSchemaTarget(new SQLitePlatform()))->applySchema($schema);
        $this->assertSame('NOCASE', $sqlite->getTable('records')->getColumn('code')->getCollation());
        $this->assertNull($sqlite->getTable('records')->getOption('charset'));
        $unspecified = (new DoctrineSchemaTarget())->applySchema($schema);
        $this->assertNull($unspecified->getTable('records')->getColumn('code')->getCollation());
    }

    public function testSqliteUsesItsOwnCollationAndIgnoresMysqlMetadata(): void
    {
        $source = new SpreadsheetDatabase((new SpreadsheetSchemaTarget())->applySchema($this->schema()));
        $source->load(['records' => [['code' => 'Abc']]]);
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new Pipeline())->extract($source)->transform()->load($target)->execute();
        $this->assertSame('Abc', $target->connection()->fetchOne("SELECT code FROM records WHERE code = 'abc'"));
        $options = $target->schema()->getTable('records')?->getColumn('code')?->getPlatformOptions();
        $this->assertSame(['sqlite' => ['collation' => 'NOCASE']], $options);

        // The standalone SQL exporter must apply the same engine-specific rule.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec((new SqliteSchemaTarget())->applySchema($this->schema()));
        $pdo->exec("INSERT INTO records (code) VALUES ('Abc')");
        $statement = $pdo->query("SELECT code FROM records WHERE code = 'abc'");
        $this->assertNotFalse($statement);
        $this->assertSame('Abc', $statement->fetchColumn());
    }

    public function testOldSpreadsheetsAndAbsentOptionsRemainUnspecified(): void
    {
        $table = (new Table('records'))->addColumn(new Column('code', 'string'));
        $schema = (new Schema())->addTable($table);
        $document = (new SpreadsheetSchemaTarget())->applySchema($schema);
        foreach ($document->getSheet('__schema')?->getRows() ?? [] as $row) {
            if (is_array($row['properties'] ?? null)) {
                $this->assertArrayNotHasKey('platform_options', $row['properties']);
            }
        }
        $restored = (new SpreadsheetSchemaSource())->extractSchema($document);
        $restoredTable = $restored->getTable('records');
        $this->assertNotNull($restoredTable);
        $this->assertSame([], $restoredTable->getPlatformOptions());
        $this->assertSame([], $restoredTable->getColumn('code')?->getPlatformOptions());
        $table->setPlatformOptions(['mysql' => ['charset' => null, 'collation' => null]]);
        $this->assertSame([], $table->getPlatformOptions());
    }

    /** @return iterable<string, array{bool, array<array-key, mixed>}> */
    public static function invalidOptions(): iterable
    {
        foreach ([false, true] as $table) {
            foreach ([
                'empty engine' => ['' => []],
                'numeric engine' => [1 => []],
                'invalid settings' => ['mysql' => 'utf8mb4'],
                'unknown field' => ['mysql' => ['engine' => 'InnoDB']],
                'invalid charset' => ['mysql' => ['charset' => 123]],
                'empty collation' => ['mysql' => ['collation' => '']],
            ] as $name => $options) {
                yield ($table ? 'table ' : 'column ') . $name => [$table, $options];
            }
        }
    }

    /** @param array<array-key, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testInvalidSettingsAreRejectedWithoutReplacingExistingMetadata(bool $table, array $options): void
    {
        $model = $table ? new Table('records') : new Column('code', 'string');
        $model->setPlatformOptions(['mysql' => ['charset' => 'utf8mb4']]);
        try {
            $model->setPlatformOptions($options);
            $this->fail('Invalid engine metadata must not be accepted or cast.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame(['mysql' => ['charset' => 'utf8mb4']], $model->getPlatformOptions());
    }
}
