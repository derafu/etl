<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema\Target;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\DBAL\Types\StringType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
final class DoctrineSchemaTargetTest extends TestCase
{
    public function testApplySchema(): void
    {
        // Load the original Doctrine DBAL schema.
        $originalDoctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Convert to our schema and back to Doctrine DBAL.
        $schemaSource = new DoctrineSchemaSource();
        $ourSchema = $schemaSource->extractSchema($originalDoctrineSchema);

        $schemaTarget = new DoctrineSchemaTarget();
        $resultDoctrineSchema = $schemaTarget->applySchema($ourSchema);

        // Verify the result is a Doctrine Schema
        $this->assertInstanceOf(DoctrineSchema::class, $resultDoctrineSchema);

        // Verify that all tables are present
        $expectedTables = [
            'party', 'item', 'tax_scheme', 'tax_category',
            'invoice', 'invoice_line', 'invoice_line_tax',
        ];

        foreach ($expectedTables as $tableName) {
            $this->assertTrue(
                $resultDoctrineSchema->hasTable($tableName),
                "Schema should have table '{$tableName}'."
            );
        }

        // Test specific table structure (e.g., invoice)
        $invoiceTable = $resultDoctrineSchema->getTable('invoice');

        // Check columns.
        $this->assertTrue(
            $invoiceTable->hasColumn('id'),
            "Invoice table should have 'id' column."
        );
        $this->assertTrue(
            $invoiceTable->hasColumn('customer_id'),
            "Invoice table should have 'customer_id' column."
        );
        $this->assertTrue(
            $invoiceTable->hasColumn('supplier_id'),
            "Invoice table should have 'supplier_id' column."
        );

        // Check primary key.
        $primaryKey = $invoiceTable->getPrimaryKey();
        $this->assertNotNull(
            $primaryKey,
            "Invoice table should have a primary key."
        );
        $this->assertSame(
            ['id'],
            $primaryKey->getColumns(),
            "Invoice primary key should be 'id'."
        );

        // Check foreign keys.
        $foreignKeys = $invoiceTable->getForeignKeys();
        $this->assertNotEmpty(
            $foreignKeys,
            "Invoice table should have foreign keys."
        );

        $hasPartyFk = false;
        foreach ($foreignKeys as $fk) {
            if ($fk->getForeignTableName() === 'party') {
                $hasPartyFk = true;
                break;
            }
        }
        $this->assertTrue(
            $hasPartyFk,
            "Invoice table should have a foreign key to party table."
        );

        // Check indexes.
        $indexes = $invoiceTable->getIndexes();
        $this->assertNotEmpty($indexes, "Invoice table should have indexes.");
    }

    public function testRoundTrip(): void
    {
        // Test roundtrip conversion: Doctrine DBAL -> Our Schema -> Doctrine DBAL.
        $originalDoctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Original schema tables and columns.
        $originalTables = [];
        foreach ($originalDoctrineSchema->getTables() as $table) {
            $tableName = $table->getName();
            $originalTables[$tableName] = [];

            foreach ($table->getColumns() as $column) {
                $originalTables[$tableName][] = $column->getName();
            }

            sort($originalTables[$tableName]);
        }

        // Convert to our schema and back.
        $schemaSource = new DoctrineSchemaSource();
        $ourSchema = $schemaSource->extractSchema($originalDoctrineSchema);

        $schemaTarget = new DoctrineSchemaTarget();
        $resultDoctrineSchema = $schemaTarget->applySchema($ourSchema);

        // Resulting schema tables and columns.
        $resultTables = [];
        foreach ($resultDoctrineSchema->getTables() as $table) {
            $tableName = $table->getName();
            $resultTables[$tableName] = [];

            foreach ($table->getColumns() as $column) {
                $resultTables[$tableName][] = $column->getName();
            }

            sort($resultTables[$tableName]);
        }

        // Compare the structure.
        $this->assertSame(
            array_keys($originalTables),
            array_keys($resultTables),
            "Same tables should be present after roundtrip conversion."
        );

        foreach ($originalTables as $tableName => $columns) {
            $this->assertSame(
                $columns,
                $resultTables[$tableName],
                "Same columns should be present in table '{$tableName}' after roundtrip conversion."
            );
        }
    }

    public function testTypeMapping(): void
    {
        // Create a simple test schema with different column types.
        $originalDoctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Extract our schema.
        $schemaSource = new DoctrineSchemaSource();
        $ourSchema = $schemaSource->extractSchema($originalDoctrineSchema);

        // Convert back to Doctrine DBAL.
        $schemaTarget = new DoctrineSchemaTarget();
        $resultDoctrineSchema = $schemaTarget->applySchema($ourSchema);

        // Check type mapping for some key columns.
        $this->assertInstanceOf(
            StringType::class,
            $resultDoctrineSchema->getTable('party')->getColumn('name')->getType(),
            "String type should be correctly mapped"
        );

        $this->assertInstanceOf(
            DecimalType::class,
            $resultDoctrineSchema->getTable('invoice')->getColumn('total_amount')->getType(),
            "Decimal type should be correctly mapped"
        );

        $this->assertInstanceOf(
            GuidType::class,
            $resultDoctrineSchema->getTable('invoice')->getColumn('id')->getType(),
            "GUID type should be correctly mapped"
        );

        $this->assertInstanceOf(
            DateType::class,
            $resultDoctrineSchema->getTable('invoice')->getColumn('issue_date')->getType(),
            "Date type should be correctly mapped"
        );
    }
}
