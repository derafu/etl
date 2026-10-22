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
use Derafu\ETL\Transform\DataRules;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\TestsETL\Fixture\LegacySqliteDatabase;
use Derafu\TestsETL\Fixture\TemporaryFiles;
use Derafu\TestsETL\Fixture\TrimProductNames;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
final class PipelineOperationsTest extends TestCase
{
    use TemporaryFiles;

    public function testCustomAdaptersWithoutTheTransactionContractStillSynchronizeAndLoadSqlite(): void
    {
        $source = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->connection()->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
        $source->connection()->executeStatement('CREATE TABLE empty_records (id INTEGER PRIMARY KEY)');
        $source->connection()->insert('products', ['id' => 1, 'name' => 'Laptop']);
        $target = new LegacySqliteDatabase();

        $pipeline = (new Pipeline())->extract($source)->transform([
            static function (array $data): array {
                $data['products'][0]['name'] = strtoupper($data['products'][0]['name']);
                return $data;
            },
        ])->load($target);
        $result = $pipeline->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $this->assertSame($target, $result->target()->database());
        $this->assertSame(['empty_records', 'products'], $target->connection()->createSchemaManager()->listTableNames());
        $this->assertSame([['id' => 1, 'name' => 'LAPTOP']], $target->connection()->fetchAllAssociative('SELECT * FROM products'));
        $this->assertSame([], $target->data(['table' => 'empty_records']));
        $this->assertSame([['id' => 1, 'name' => 'Laptop']], $source->data(['table' => 'products']));
        $this->assertSame(0, $target->connection()->getTransactionNestingLevel());
    }

    public function testRulesRunInOrderAndThePipelineCanBeResetAndReused(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement("INSERT INTO products VALUES (1, ' Laptop ')");
        $source = new DoctrineDatabase($connection);
        $target = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $pipeline = (new Pipeline())->extract($source)->transform([
            new TrimProductNames(),
            static function (array $data): array {
                $data['audit'] = [['message' => 'normalized']];
                return $data;
            },
        ])->load($target);
        $target->connection()->executeStatement('CREATE TABLE audit (message TEXT)');
        // Keep the audit table while using the source table structure explicitly.
        $target->connection()->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
        $result = $pipeline->execute(['sync' => false]);
        $this->assertSame(2, $result->rowsLoaded());
        $this->assertSame($target, $result->target()->database());
        $this->assertSame([['id' => 1, 'name' => 'Laptop']], $target->data(['table' => 'products']));
        $this->assertSame([['message' => 'normalized']], $target->data(['table' => 'audit']));
        $this->assertSame([['id' => 1, 'name' => ' Laptop ']], $source->data(['table' => 'products']));
        $this->assertSame($pipeline, $pipeline->reset());
        $other = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $result = $pipeline->extract($source)->transform()->load($other)->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $this->assertSame([['id' => 1, 'name' => ' Laptop ']], $other->data(['table' => 'products']));
        $this->assertSame($other, $result->target()->database());
    }

    /** @return iterable<string, array{int, string}> */
    public static function incompletePipelines(): iterable
    {
        yield 'missing source' => [0, 'Source'];
        yield 'missing rules' => [1, 'Rules'];
        yield 'missing target' => [2, 'Target'];
        yield 'reset pipeline' => [3, 'Source'];
    }

    #[DataProvider('incompletePipelines')]
    public function testIncompleteAndResetPipelinesExplainTheMissingStep(int $steps, string $missing): void
    {
        $database = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
        $pipeline = new Pipeline();
        if ($steps > 0) {
            $pipeline->extract($database);
        }
        if ($steps > 1) {
            $pipeline->transform();
        }
        if ($steps > 2) {
            $pipeline->load($database)->reset();
        }
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage($missing . ($missing === 'Rules' ? ' are' : ' is') . ' not set in the ETL pipeline.');
        $pipeline->execute();
    }

    /** @return iterable<string, array{bool}> */
    public static function confirmationModes(): iterable
    {
        yield 'skip confirmation' => [false];
        yield 'confirm interactively' => [true];
    }

    #[DataProvider('confirmationModes')]
    public function testCommandLoadsARealJsonFileIntoSqlite(bool $interactive): void
    {
        $source = $this->directory . '/products.json';
        $target = $this->directory . '/products.sqlite';
        file_put_contents($source, '{"products":[{"id":"P001","name":"Laptop"}]}');
        $tester = new CommandTester(new PipelineCommand());
        $arguments = ['source' => $source, 'target' => $target];
        if ($interactive) {
            $tester->setInputs(['yes']);
        } else {
            $arguments['--no-confirm'] = true;
        }
        $this->assertSame(Command::SUCCESS, $tester->execute($arguments, ['interactive' => $interactive]));
        $this->assertStringContainsString('ETL pipeline process completed successfully.', $tester->getDisplay());
        $database = new DoctrineDatabase($target);
        $this->assertSame([['id' => 'P001', 'name' => 'Laptop']], $database->data(['table' => 'products']));
    }

    public function testCommandReportsARealMissingSourceFileFailure(): void
    {
        $tester = new CommandTester(new PipelineCommand());
        $this->assertSame(Command::FAILURE, $tester->execute([
            'source' => $this->directory . '/missing.xlsx',
            'target' => $this->directory . '/unused.sqlite',
            '--no-confirm' => true,
        ]));
        $this->assertStringContainsString('Error during the ETL pipeline process:', $tester->getDisplay());
        $this->assertStringContainsString('File does not exist', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->directory . '/unused.sqlite');
    }
}
