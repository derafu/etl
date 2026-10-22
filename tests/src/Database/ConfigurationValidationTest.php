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
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DatabaseManager::class)]
#[CoversClass(SpreadsheetDatabase::class)]
#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(AbstractDatabase::class)]
#[UsesClass(SpreadsheetSchemaSource::class)]
#[UsesClass(SpreadsheetSchemaTarget::class)]
#[UsesClass(Column::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Table::class)]
#[UsesClass(EtlTranslationResourceProvider::class)]
final class ConfigurationValidationTest extends TestCase
{
    public function testSelectingAMissingTableProducesATranslatableException(): void
    {
        $source = new Spreadsheet();
        $source->createSheet('Products', [['id' => 1]], true);
        $database = new SpreadsheetDatabase($source);

        try {
            $database->data(['table' => 'Missing']);
            $this->fail('Selecting a missing table must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Table "Missing" not found in spreadsheet.', $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertSame('No se encontró la tabla "Missing" en la planilla.', $exception->trans($translator));
        }

        $this->assertSame([['id' => 1]], $database->data(['table' => 'Products']));
        $this->assertSame(['Products' => [['id' => 1]]], $database->data(['table' => null]));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidConnections(): iterable
    {
        yield 'file' => [['file' => 123], 'file'];
        yield 'file format' => [['file' => 'unused.json', 'format' => []], 'format'];
        yield 'data' => [['data' => 123, 'format' => 'json'], 'data'];
        yield 'data format' => [['data' => '{}', 'format' => []], 'format'];
        yield 'doctrine' => [['doctrine' => true], 'doctrine'];
        yield 'spreadsheet' => [['spreadsheet' => new stdClass()], 'spreadsheet'];
    }

    /** @param array<array-key, mixed> $options */
    #[DataProvider('invalidConnections')]
    public function testInvalidConnectionOptionTypesAreRejected(array $options, string $option): void
    {
        try {
            (new DatabaseManager())->connect($options);
            $this->fail('An invalid connection option type must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Option "' . $option . '"', $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertStringContainsString('La opción "' . $option . '"', $exception->trans($translator));
        }
    }

    public function testConnectionObjectsAndJsonDataRetainTheirSupportedBehavior(): void
    {
        $manager = new DatabaseManager();
        $source = new Spreadsheet();
        $source->createSheet('Products', [['id' => 1]], true);
        $this->assertSame($source, $manager->connect($source)->connection());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->assertSame($connection, $manager->connect($connection)->connection());
        $loaded = $manager->connect(['data' => '{"Products":[{"id":1,"name":"Laptop"}]}', 'format' => 'json']);
        $this->assertSame([['id' => 1, 'name' => 'Laptop']], $loaded->data(['table' => 'Products']));
    }

    /** @return iterable<string, array{string, array<array-key, mixed>, string}> */
    public static function invalidDatabaseOptions(): iterable
    {
        yield 'dump format' => ['dump', ['format' => []], 'format'];
        yield 'selected table' => ['data', ['table' => []], 'table'];
        yield 'load format' => ['load', ['format' => []], 'format'];
        yield 'schema sheet name' => ['replace', ['dropData' => true, 'schemaSheetName' => []], 'schemaSheetName'];
    }

    /** @param array<array-key, mixed> $options */
    #[DataProvider('invalidDatabaseOptions')]
    public function testSpreadsheetDatabaseRejectsInvalidOptionTypes(string $operation, array $options, string $option): void
    {
        $source = new Spreadsheet();
        $source->createSheet('Products', [['id' => 1]], true);
        $database = new SpreadsheetDatabase($source);

        try {
            match ($operation) {
                'dump' => $database->dump($options),
                'data' => $database->data($options),
                'load' => $database->load('content', $options),
                'replace' => $database->load(new SpreadsheetDatabase($source), $options),
                default => $this->fail('Unknown test operation.'),
            };
            $this->fail('An invalid database option type must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Option "' . $option . '"', $exception->getMessage());
        }
    }

    public function testDoctrineDatabaseRejectsAnInvalidTableOptionBeforeRunningAQuery(): void
    {
        $database = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option "table" must be a string; array given.');
        $database->data(['table' => []]);
    }

    public function testSpreadsheetDumpAndLoadKeepTheDefaultFormatAndOptionalTableSelection(): void
    {
        $source = new Spreadsheet();
        $source->createSheet('Products', [['id' => 1, 'name' => 'Laptop']], true);
        $database = new SpreadsheetDatabase($source);
        $this->assertSame(['Products' => [['id' => 1, 'name' => 'Laptop']]], $database->data());
        $this->assertSame($database->data(), $database->data(['table' => null]));
        $this->assertSame([['id' => 1, 'name' => 'Laptop']], $database->data(['table' => 'Products']));
        $dump = $database->dump(['format' => null]);
        $copy = new SpreadsheetDatabase(new Spreadsheet());
        $copy->load($dump, ['format' => 'xlsx', 'dropDatabase' => true]);
        $this->assertSame($database->data(), $copy->data());
    }
}
