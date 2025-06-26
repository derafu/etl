<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetLoader;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test that a Schema can be converted to a Spreadsheet and back to a Schema
 * and that the original and regenerated Schemas are identical.
 */
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
final class SpreadsheetSchemaRoundTripTest extends TestCase
{
    public function testRoundTripSchemaConversion(): void
    {
        // 1. Load the original Doctrine DBAL schema.
        $originalDoctrineSchema = require __DIR__ . '/../../fixtures/doctrine-dbal-schema.php';
        $this->assertInstanceOf(DoctrineSchema::class, $originalDoctrineSchema);

        // 2. Convert Doctrine DBAL Schema to our Schema model.
        $schemaSource = new DoctrineSchemaSource();
        $ourSchema = $schemaSource->extractSchema($originalDoctrineSchema);

        // 3. Convert our Schema to a Spreadsheet.
        $spreadsheetTarget = new SpreadsheetSchemaTarget();
        $spreadsheet = $spreadsheetTarget->applySchema($ourSchema);

        // 4. Get the JSON representation of the Spreadsheet.
        $dumper = new SpreadsheetDumper();
        $json = $dumper->dumpToString($spreadsheet, 'json');
        $this->assertJson($json);

        // 5. Load the JSON representation of the Spreadsheet.
        $loader = new SpreadsheetLoader();
        $loadedSpreadsheet = $loader->loadFromString($json, 'json');

        // 6. Convert the Spreadsheet back to our Schema model.
        $spreadsheetSource = new SpreadsheetSchemaSource();
        $regeneratedSchema = $spreadsheetSource->extractSchema($loadedSpreadsheet);

        // 7. Convert our regenerated Schema back to Doctrine DBAL Schema.
        $doctrineTarget = new DoctrineSchemaTarget();
        $regeneratedDoctrineSchema = $doctrineTarget->applySchema($regeneratedSchema);

        // 8. Compare the original and regenerated Doctrine DBAL schemas.
        $this->assertInstanceOf(DoctrineSchema::class, $regeneratedDoctrineSchema);

        // Compare table count.
        $this->assertCount(
            count($originalDoctrineSchema->getTables()),
            $regeneratedDoctrineSchema->getTables(),
            'The number of tables should match.'
        );

        // Compare table names.
        $originalTableNames = $this->getTableNames($originalDoctrineSchema);
        $regeneratedTableNames = $this->getTableNames($regeneratedDoctrineSchema);
        $this->assertSame(
            sort($originalTableNames),
            sort($regeneratedTableNames),
            'Table names should match.'
        );

        // Compare each table in detail.
        foreach ($originalDoctrineSchema->getTables() as $originalTable) {
            $tableName = $originalTable->getName();
            $this->assertTrue(
                $regeneratedDoctrineSchema->hasTable($tableName),
                "Regenerated schema should have table '$tableName'."
            );

            $regeneratedTable = $regeneratedDoctrineSchema->getTable($tableName);

            // Compare column count.
            $this->assertCount(
                count($originalTable->getColumns()),
                $regeneratedTable->getColumns(),
                "Column count for table '$tableName' should match."
            );

            // Compare column names.
            $originalColumnNames = $this->getColumnNames($originalTable);
            $regeneratedColumnNames = $this->getColumnNames($regeneratedTable);
            $this->assertSame(
                sort($originalColumnNames),
                sort($regeneratedColumnNames),
                "Column names for table '$tableName' should match."
            );

            // Compare primary keys.
            $originalPrimaryKey = $originalTable->getPrimaryKey();
            $regeneratedPrimaryKey = $regeneratedTable->getPrimaryKey();

            if ($originalPrimaryKey !== null && $regeneratedPrimaryKey !== null) {
                $originalPrimaryKeyColumns = $originalPrimaryKey->getColumns();
                $regeneratedPrimaryKeyColumns = $regeneratedPrimaryKey->getColumns();
                $this->assertSame(
                    sort($originalPrimaryKeyColumns),
                    sort($regeneratedPrimaryKeyColumns),
                    "Primary key columns for table '$tableName' should match."
                );
            } else {
                $this->assertSame(
                    $originalPrimaryKey,
                    $regeneratedPrimaryKey,
                    "Primary key for table '$tableName' should be the same (both null or both not null)."
                );
            }

            // Compare foreign keys (at least count should match).
            $this->assertCount(
                count($originalTable->getForeignKeys()),
                $regeneratedTable->getForeignKeys(),
                "Foreign key count for table '$tableName' should match."
            );

            // Compare indexes (at least count should match).
            $this->assertCount(
                count($originalTable->getIndexes()),
                $regeneratedTable->getIndexes(),
                "Index count for table '$tableName' should match."
            );
        }
    }

    /**
     * Helper method to get table names from a Doctrine Schema.
     */
    private function getTableNames(DoctrineSchema $schema): array
    {
        $tableNames = [];
        foreach ($schema->getTables() as $table) {
            $tableNames[] = $table->getName();
        }
        return $tableNames;
    }

    /**
     * Helper method to get column names from a Doctrine Table.
     */
    private function getColumnNames(DoctrineTable $table): array
    {
        $columnNames = [];
        foreach ($table->getColumns() as $column) {
            $columnNames[] = $column->getName();
        }
        return $columnNames;
    }
}
