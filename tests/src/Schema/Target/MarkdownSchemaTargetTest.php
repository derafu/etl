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
use Derafu\ETL\Schema\Target\MarkdownSchemaTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownSchemaTarget::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
final class MarkdownSchemaTargetTest extends TestCase
{
    public function testApplySchema(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Create the markdown schema target.
        $schemaTarget = new MarkdownSchemaTarget();

        // Apply the schema to get the markdown representation.
        $markdownOutput = $schemaTarget->applySchema($schema);

        // Verify the output is a non-empty string.
        $this->assertIsString($markdownOutput);
        $this->assertNotEmpty($markdownOutput);

        // Verify markdown structure.
        $this->assertStringContainsString(
            "# Database Schema",
            $markdownOutput,
            "Output should have a top-level heading."
        );
        $this->assertStringContainsString(
            "## Table of Contents",
            $markdownOutput,
            "Output should have a table of contents."
        );

        // Verify that the markdown contains the expected table headings.
        $expectedTables = [
            'party', 'item', 'tax_scheme', 'tax_category',
            'invoice', 'invoice_line', 'invoice_line_tax',
        ];

        foreach ($expectedTables as $tableName) {
            $this->assertStringContainsString(
                "## Table: {$tableName}",
                $markdownOutput,
                "Output should have a heading for table '{$tableName}'."
            );
        }

        // Verify that column details are included.
        $this->assertStringContainsString(
            "### Columns",
            $markdownOutput,
            "Output should have a columns section."
        );
        $this->assertStringContainsString(
            "| Column | Type | Attributes | Description |",
            $markdownOutput,
            "Output should have a columns table."
        );
        $this->assertStringContainsString(
            "NOT NULL",
            $markdownOutput,
            "Output should show NOT NULL constraints."
        );

        // Verify that primary keys are included.
        $this->assertStringContainsString(
            "### Primary Key",
            $markdownOutput,
            "Output should have a primary key section."
        );

        // Verify that foreign keys are included.
        $this->assertStringContainsString(
            "### Foreign Keys",
            $markdownOutput,
            "Output should have a foreign keys section."
        );
        $this->assertStringContainsString(
            "| Name | Columns | References | On Delete | On Update |",
            $markdownOutput,
            "Output should have a foreign keys table."
        );

        // Verify that indexes are included.
        $this->assertStringContainsString(
            "### Indexes",
            $markdownOutput,
            "Output should have an indexes section."
        );
        $this->assertStringContainsString(
            "| Name | Columns | Type | Flags |",
            $markdownOutput,
            "Output should have an indexes table."
        );
        $this->assertStringContainsString(
            "UNIQUE",
            $markdownOutput,
            "Output should show unique indexes."
        );

        // Save the markdown to a file for inspection (optional)
        // file_put_contents('schema_documentation.md', $markdownOutput);

        // Uncomment to see the output during test execution
        // echo $markdownOutput;
    }
}
