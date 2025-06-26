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
use Derafu\ETL\Schema\Target\D2SchemaTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(D2SchemaTarget::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
final class D2SchemaTargetTest extends TestCase
{
    public function testApplySchema(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Create the D2 schema target with default options.
        $schemaTarget = new D2SchemaTarget();

        // Apply the schema to get the D2 diagram code.
        $d2Output = $schemaTarget->applySchema($schema);

        // Verify the output is a non-empty string.
        $this->assertIsString($d2Output);
        $this->assertNotEmpty($d2Output);

        // Verify D2 basic structure.
        $this->assertStringContainsString(
            "direction: right",
            $d2Output,
            "Output should define diagram direction."
        );
        $this->assertStringContainsString(
            "shape: sql_table",
            $d2Output,
            "Output should use sql_table shape."
        );

        // Verify that all tables are included.
        $expectedTables = [
            'party', 'item', 'tax_scheme', 'tax_category',
            'invoice', 'invoice_line', 'invoice_line_tax',
        ];

        foreach ($expectedTables as $tableName) {
            $this->assertStringContainsString(
                "{$tableName}: {",
                $d2Output,
                "Output should include table '{$tableName}'."
            );
        }

        // Verify that relationships are defined.
        $this->assertStringContainsString(
            "->",
            $d2Output,
            "Output should include relationship arrows."
        );

        // Save the D2 code to a file for inspection (optional).
        // file_put_contents('schema_diagram.d2', $d2Output);

        // Uncomment to see the output during test execution.
        // echo $d2Output;
    }

    public function testDifferentDetailLevels(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Test with DETAIL_KEYS_ONLY.
        $keysOnlyTarget = new D2SchemaTarget(
            D2SchemaTarget::DETAIL_KEYS_ONLY,
            D2SchemaTarget::DIRECTION_RIGHT
        );
        $keysOnlyOutput = $keysOnlyTarget->applySchema($schema);

        // Verify that the output is different from the full detail level.
        $fullDetailTarget = new D2SchemaTarget(
            D2SchemaTarget::DETAIL_FULL,
            D2SchemaTarget::DIRECTION_RIGHT
        );
        $fullDetailOutput = $fullDetailTarget->applySchema($schema);

        $this->assertNotSame(
            $keysOnlyOutput,
            $fullDetailOutput,
            "Keys-only output should be different from full detail output."
        );
        $this->assertLessThan(
            strlen($fullDetailOutput),
            strlen($keysOnlyOutput),
            "Keys-only output should be shorter than full detail output"
        );

        // Test with DETAIL_MINIMAL.
        $minimalTarget = new D2SchemaTarget(
            D2SchemaTarget::DETAIL_MINIMAL,
            D2SchemaTarget::DIRECTION_RIGHT
        );
        $minimalOutput = $minimalTarget->applySchema($schema);

        $this->assertLessThan(
            strlen($keysOnlyOutput),
            strlen($minimalOutput),
            "Minimal output should be shorter than keys-only output."
        );
    }

    public function testFilteredTables(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Create the D2 schema target with a filter for specific tables.
        $filteredTables = ['invoice', 'party'];
        $schemaTarget = new D2SchemaTarget(
            D2SchemaTarget::DETAIL_FULL,
            D2SchemaTarget::DIRECTION_RIGHT,
            D2SchemaTarget::LAYOUT_DEFAULT,
            false,
            $filteredTables
        );

        // Apply the schema to get the D2 diagram code.
        $d2Output = $schemaTarget->applySchema($schema);

        // Verify that only filtered tables are included.
        foreach ($filteredTables as $tableName) {
            $this->assertStringContainsString(
                "{$tableName}: {",
                $d2Output,
                "Output should include filtered table '{$tableName}'."
            );
        }

        // Verify that other tables are not included.
        $excludedTables = ['invoice_line', 'item', 'tax_scheme'];
        foreach ($excludedTables as $tableName) {
            $this->assertStringNotContainsString(
                "{$tableName}: {",
                $d2Output,
                "Output should not include excluded table '{$tableName}'."
            );
        }
    }
}
