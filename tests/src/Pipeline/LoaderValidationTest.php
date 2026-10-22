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
use Derafu\ETL\Extract\DataExtractor;
use Derafu\ETL\Extract\DataSource;
use Derafu\ETL\Load\DataLoader;
use Derafu\ETL\Load\DataTarget;
use Derafu\ETL\Pipeline\Pipeline;
use Derafu\ETL\Pipeline\PipelineResult;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Transform\DataRules;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataLoader::class)]
#[UsesClass(AbstractDatabase::class)]
#[UsesClass(DoctrineDatabase::class)]
#[UsesClass(DatabaseManager::class)]
#[UsesClass(DataTarget::class)]
#[UsesClass(DataExtractor::class)]
#[UsesClass(DataSource::class)]
#[UsesClass(Pipeline::class)]
#[UsesClass(PipelineResult::class)]
#[UsesClass(DataRules::class)]
#[UsesClass(DataTransformer::class)]
#[UsesClass(DoctrineSchemaSource::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Table::class)]
#[UsesClass(Column::class)]
#[UsesClass(EtlTranslationResourceProvider::class)]
final class LoaderValidationTest extends TestCase
{
    private function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidData(): iterable
    {
        yield 'string rows' => [['invalid' => 'rows'], 'must be an array'];
        yield 'null rows' => [['invalid' => null], 'must be an array'];
        yield 'integer rows' => [['invalid' => 123], 'must be an array'];
        yield 'boolean rows' => [['invalid' => false], 'must be an array'];
        yield 'empty table name' => [['' => []], 'Table names must not be empty.'];
    }

    /** @param array<array-key, mixed> $invalid */
    #[DataProvider('invalidData')]
    public function testAllTablesAreValidatedBeforeAnyTableIsLoaded(array $invalid, string $message): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $target = new DataTarget(new DoctrineDatabase($connection));

        try {
            (new DataLoader())->load($target, ['records' => [['id' => 1]], ...$invalid]);
            $this->fail('Invalid transformed table data must be rejected before loading starts.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
        $this->assertSame([], $connection->fetchAllAssociative('SELECT * FROM records'));
        $this->assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testNumericTableNamesSparseRowsAndNullCellsAreLoadedWithoutChangingInput(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE "2024" (id INTEGER PRIMARY KEY, note TEXT)');
        $connection->executeStatement('CREATE TABLE "01" (id INTEGER PRIMARY KEY, note TEXT)');
        $target = new DataTarget(new DoctrineDatabase($connection));
        $data = [
            '2024' => [3 => ['id' => 1, 'note' => null], 8 => ['note' => 'Second', 'id' => 2]],
            '01' => [['id' => 3, 'note' => 'Leading zero']],
            'empty' => [],
        ];
        $original = $data;
        $loader = new DataLoader();
        $this->assertSame(3, $loader->load($target, $data));
        $this->assertSame([
            ['id' => 1, 'note' => null],
            ['id' => 2, 'note' => 'Second'],
        ], $connection->fetchAllAssociative('SELECT * FROM "2024" ORDER BY id'));
        $this->assertSame([['id' => 3, 'note' => 'Leading zero']], $connection->fetchAllAssociative('SELECT * FROM "01"'));
        $this->assertSame($original, $data);
        $this->assertSame(0, $loader->load($target, []));
    }

    public function testInvalidTransformationResultIsRejectedThroughTheRealPipeline(): void
    {
        $source = $this->connection();
        $source->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $source->executeStatement('INSERT INTO records VALUES (1)');
        $target = $this->connection();
        $target->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $pipeline = (new Pipeline())
            ->extract(new DoctrineDatabase($source))
            ->transform([static fn (array $data): array => [...$data, 'invalid' => 'rows']])
            ->load(new DoctrineDatabase($target));

        try {
            $pipeline->execute(['sync' => false]);
            $this->fail('Invalid table data produced by a transformation must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Rows for table "invalid" must be an array; string given.', $exception->getMessage());
        }
        $this->assertSame([], $target->fetchAllAssociative('SELECT * FROM records'));
        $this->assertSame([['id' => 1]], $source->fetchAllAssociative('SELECT * FROM records'));
    }
}
