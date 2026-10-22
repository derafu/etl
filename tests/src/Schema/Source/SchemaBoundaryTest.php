<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema\Source;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException as RuntimeException;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(Index::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class SchemaBoundaryTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSchemaRows(): iterable
    {
        yield 'invalid column name' => [['type' => 'column', 'name' => 'id', 'properties' => []], 'Invalid column name format'];
        yield 'invalid index name' => [['type' => 'index', 'name' => 'ix', 'properties' => []], 'Invalid index name format'];
        yield 'invalid foreign key name' => [['type' => 'foreign_key', 'name' => 'fk', 'properties' => []], 'Invalid foreign key name format'];
        yield 'missing index columns' => [['type' => 'index', 'name' => 'records.ix', 'properties' => []], 'Missing columns for index'];
        yield 'missing foreign key properties' => [['type' => 'foreign_key', 'name' => 'records.fk', 'properties' => []], 'Missing required properties'];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidSchemaRows')]
    public function testMalformedSchemaRowsProduceTranslatableErrors(array $row, string $message): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet('__schema', [
            ['type' => 'table', 'name' => 'records', 'properties' => []],
            $row,
        ], true);
        try {
            (new SpreadsheetSchemaSource())->extractSchema($spreadsheet);
            $this->fail('Malformed schema rows must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
    }

    public function testSpreadsheetSourceRejectsADoctrineSchema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$spreadsheet must be an instance of SpreadsheetInterface.');
        (new SpreadsheetSchemaSource())->extractSchema(new DoctrineSchema());
    }

    public function testDoctrineSourceRejectsASpreadsheet(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Doctrine DBAL Schema must be an instance of DoctrineSchema.');
        (new DoctrineSchemaSource())->extractSchema(new Spreadsheet());
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteConstraints(): iterable
    {
        yield 'index without columns' => ['index'];
        yield 'foreign key without local columns' => ['local'];
        yield 'foreign key without foreign columns' => ['foreign'];
    }

    #[DataProvider('incompleteConstraints')]
    public function testDoctrineTargetRejectsIncompleteConstraints(string $case): void
    {
        $table = new Table('records');
        $table->addColumn(new Column('id', 'integer'));
        if ($case === 'index') {
            $table->addIndex(new Index('ix'));
        } else {
            $table->addForeignKey(new ForeignKey('records', $case === 'local' ? [] : ['id'], $case === 'foreign' ? [] : ['id']));
        }
        $schema = (new Schema())->addTable($table);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($case === 'index' ? 'Index must have columns.' : 'Foreign key must have local and foreign columns.');
        (new DoctrineSchemaTarget())->applySchema($schema);
    }
}
