<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema\Source;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Table;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
final class DoctrineSchemaSourceTest extends TestCase
{
    public function testExtractSchema(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Make sure it's a valid Doctrine DBAL Schema.
        $this->assertInstanceOf(DoctrineSchema::class, $doctrineSchema);

        // Create the schema source.
        $schemaSource = new DoctrineSchemaSource();

        // Execute the method we're testing.
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Test that the result is an instance of our SchemaInterface.
        $this->assertInstanceOf(SchemaInterface::class, $schema);

        // Verify expected tables are present.
        $expectedTables = [
            'party', 'item', 'tax_scheme', 'tax_category',
            'invoice', 'invoice_line', 'invoice_line_tax',
        ];

        foreach ($expectedTables as $tableName) {
            $this->assertTrue($schema->hasTable($tableName), "Schema should have table '$tableName'");
        }

        // Check a specific table structure.
        $invoiceTable = $schema->getTable('invoice');
        $this->assertNotNull($invoiceTable);
        $this->assertTrue($invoiceTable->hasColumn('id'));
        $this->assertTrue($invoiceTable->hasColumn('customer_id'));
        $this->assertTrue($invoiceTable->hasColumn('supplier_id'));
        $this->assertTrue($invoiceTable->hasColumn('total_amount'));

        // Check foreign keys exist.
        $foreignKeys = $invoiceTable->getForeignKeys();
        $this->assertNotEmpty($foreignKeys);

        // Verify that at least one foreign key points to 'party' table.
        $partyFkFound = false;
        foreach ($foreignKeys as $fk) {
            if ($fk->getForeignTableName() === 'party') {
                $partyFkFound = true;
                break;
            }
        }
        $this->assertTrue(
            $partyFkFound,
            "Invoice should have a foreign key to the party table."
        );
    }
}
