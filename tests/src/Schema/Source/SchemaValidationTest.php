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
use Derafu\ETL\Schema\Enum\IndexType;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException as RuntimeException;
use Derafu\Translation\TranslatorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpreadsheetSchemaSource::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Table::class)]
#[UsesClass(Column::class)]
#[UsesClass(Index::class)]
#[UsesClass(ForeignKey::class)]
#[UsesClass(IndexType::class)]
#[UsesClass(EtlTranslationResourceProvider::class)]
final class SchemaValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function missingTableReferences(): iterable
    {
        yield 'column' => [['type' => 'column', 'name' => 'missing.id', 'properties' => ['type' => 'integer']]];
        yield 'index' => [['type' => 'index', 'name' => 'missing.ix', 'properties' => ['columns' => ['id']]]];
        yield 'foreign key' => [['type' => 'foreign_key', 'name' => 'missing.fk', 'properties' => ['foreign_table' => 'products', 'local_columns' => ['id'], 'foreign_columns' => ['id']]]];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('missingTableReferences')]
    public function testMissingTableReferencesKeepTheirExistingTranslatableException(array $row): void
    {
        $source = new Spreadsheet();
        $source->createSheet('__schema', [
            ['type' => 'table', 'name' => 'products', 'properties' => []],
            $row,
        ], true);

        try {
            (new SpreadsheetSchemaSource())->extractSchema($source);
            $this->fail('A reference to a missing table must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Table "missing" not found in schema sheet.', $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertSame('No se encontró la tabla "missing" en la hoja del esquema.', $exception->trans($translator));
        }
    }

    public function testAMissingSchemaSheetStillInfersTablesAndColumnsFromTheData(): void
    {
        $source = new Spreadsheet();
        $source->createSheet('products', [['id' => 1, 'name' => null], ['id' => 2, 'name' => 'Laptop']], true);

        $schema = (new SpreadsheetSchemaSource())->extractSchema($source);

        $this->assertCount(1, $schema->getTables());
        $table = $schema->getTable('products');
        $this->assertNotNull($table);
        $this->assertSame(['id'], $table->getPrimaryKey());
        $columns = $table->getColumns();
        $this->assertSame(['id', 'name'], array_keys($columns));
        foreach ($columns as $column) {
            $this->assertSame('string', $column->getType());
        }
        $this->assertSame([['id' => 1, 'name' => null], ['id' => 2, 'name' => 'Laptop']], $source->getActiveSheet()->getRows());
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidRows(): iterable
    {
        yield 'row type' => [['type' => [], 'name' => 'products', 'properties' => []], 'row.type'];
        yield 'row name' => [['type' => 'table', 'name' => [], 'properties' => []], 'table.name'];
        yield 'row properties' => [['type' => 'column', 'name' => 'products.id', 'properties' => false], 'column.properties'];
        yield 'metadata properties' => [['type' => 'metadata', 'name' => 'schema', 'properties' => 'invalid'], 'metadata.properties'];
        yield 'metadata name' => [['type' => 'metadata', 'name' => 'schema', 'properties' => ['name' => []]], 'metadata.name'];
        yield 'primary key array' => [['type' => 'table', 'name' => 'products', 'properties' => ['primary_key' => 'id']], 'table.primary_key'];
        yield 'primary key strings' => [['type' => 'table', 'name' => 'products', 'properties' => ['primary_key' => [1]]], 'table.primary_key'];
        foreach ([
            'type' => [],
            'nullable' => 'yes',
            'length' => '10',
            'precision' => '12',
            'scale' => 2.5,
        ] as $field => $invalid) {
            $properties = [$field => $invalid];
            if ($field === 'scale') {
                $properties['precision'] = 10;
            }
            yield 'column ' . $field => [['type' => 'column', 'name' => 'products.id', 'properties' => $properties], 'column.' . $field];
        }
        yield 'index columns' => [['type' => 'index', 'name' => 'products.ix', 'properties' => ['columns' => [1]]], 'index.columns'];
        yield 'index flags' => [['type' => 'index', 'name' => 'products.ix', 'properties' => ['columns' => ['id'], 'flags' => 'clustered']], 'index.flags'];
        foreach ([[], 1, true] as $invalid) {
            yield 'index type ' . get_debug_type($invalid) => [['type' => 'index', 'name' => 'products.ix', 'properties' => ['columns' => ['id'], 'type' => $invalid]], 'index.type'];
        }
        foreach ([
            'foreign_table' => [],
            'local_columns' => [1],
            'foreign_columns' => [1],
            'on_delete' => false,
            'on_update' => [],
        ] as $field => $invalid) {
            $properties = ['foreign_table' => 'products', 'local_columns' => ['id'], 'foreign_columns' => ['id']];
            $properties[$field] = $invalid;
            yield 'foreign key ' . $field => [['type' => 'foreign_key', 'name' => 'products.fk', 'properties' => $properties], 'foreign_key.' . $field];
        }
    }

    /** @param array<array-key, mixed> $row */
    #[DataProvider('invalidRows')]
    public function testInvalidSchemaFieldTypesAreRejectedWithTranslatableExceptions(array $row, string $field): void
    {
        $source = new Spreadsheet();
        $source->createSheet('__schema', [
            ['type' => 'table', 'name' => 'products', 'properties' => []],
            $row,
        ], true);

        try {
            (new SpreadsheetSchemaSource())->extractSchema($source);
            $this->fail('An invalid schema field type must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Schema field "' . $field . '"', $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertStringContainsString('El campo del esquema "' . $field . '"', $exception->trans($translator));
        }
    }

    public function testValidSchemaPropertiesAndOptionalNullValuesKeepTheirMeaning(): void
    {
        $source = new Spreadsheet();
        $source->createSheet('__schema', [
            ['type' => 'metadata', 'name' => 'schema', 'properties' => ['name' => 'Catalog']],
            ['type' => 'metadata', 'name' => 'schema', 'properties' => null],
            ['type' => 'ignored', 'name' => null, 'properties' => false],
            ['type' => 'table', 'name' => 'products', 'properties' => ['primary_key' => ['id']]],
            ['type' => 'column', 'name' => 'products.id', 'properties' => ['type' => null, 'nullable' => null, 'length' => null, 'precision' => null]],
            ['type' => 'column', 'name' => 'products.price', 'properties' => ['type' => 'decimal', 'nullable' => false, 'length' => 8, 'precision' => 10, 'scale' => 2, 'default' => 0]],
            ['type' => 'column', 'name' => 'products.note', 'properties' => null],
            ['type' => 'column', 'name' => 'products.unscaled', 'properties' => ['scale' => 'ignored without precision']],
            ['type' => 'index', 'name' => 'products.ix', 'properties' => ['columns' => ['id'], 'type' => 'regular', 'clustered' => 'yes', 'flags' => null]],
            ['type' => 'foreign_key', 'name' => 'products.fk', 'properties' => ['foreign_table' => 'products', 'local_columns' => ['id'], 'foreign_columns' => ['id'], 'on_delete' => 'CASCADE', 'on_update' => 'RESTRICT']],
        ], true);

        $schema = (new SpreadsheetSchemaSource())->extractSchema($source);
        $this->assertSame('Catalog', $schema->getName());
        $table = $schema->getTable('products');
        $this->assertNotNull($table);
        $this->assertSame(['id'], $table->getPrimaryKey());
        $id = $table->getColumn('id');
        $price = $table->getColumn('price');
        $note = $table->getColumn('note');
        $unscaled = $table->getColumn('unscaled');
        $this->assertNotNull($id);
        $this->assertNotNull($price);
        $this->assertNotNull($note);
        $this->assertNotNull($unscaled);
        $this->assertSame('string', $id->getType());
        $this->assertTrue($id->isNullable());
        $this->assertNull($id->getLength());
        $this->assertNull($id->getPrecision());
        $this->assertSame('decimal', $price->getType());
        $this->assertFalse($price->isNullable());
        $this->assertSame(8, $price->getLength());
        $this->assertSame(10, $price->getPrecision());
        $this->assertSame(2, $price->getScale());
        $this->assertSame(0, $price->getDefault());
        $this->assertSame('string', $note->getType());
        $this->assertNull($unscaled->getScale());
        $index = $table->getIndex('ix');
        $this->assertNotNull($index);
        $this->assertSame(IndexType::REGULAR, $index->getType());
        $this->assertTrue($index->isClustered());
        $foreignKeys = $table->getForeignKeys();
        $this->assertCount(1, $foreignKeys);
        $foreignKey = reset($foreignKeys);
        $this->assertNotFalse($foreignKey);
        $this->assertSame('CASCADE', $foreignKey->getOnDelete());
        $this->assertSame('RESTRICT', $foreignKey->getOnUpdate());
    }
}
