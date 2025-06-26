<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Schema\Target;

use Derafu\ETL\Schema\Contract\ColumnInterface;
use Derafu\ETL\Schema\Contract\ForeignKeyInterface;
use Derafu\ETL\Schema\Contract\IndexInterface;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\SchemaTargetInterface;
use Derafu\ETL\Schema\Contract\TableInterface;
use Derafu\Spreadsheet\Contract\SheetInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Spreadsheet\SpreadsheetFactory;

/**
 * Converts a schema to a Derafu Spreadsheet format.
 */
class SpreadsheetSchemaTarget implements SchemaTargetInterface
{
    /**
     * Constructor.
     *
     * @param string $schemaSheetName The name of the schema sheet.
     */
    public function __construct(
        private readonly string $schemaSheetName = '__schema'
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function applySchema(SchemaInterface $schema): SpreadsheetInterface
    {
        // Create the spreadsheet factory.
        $factory = new SpreadsheetFactory();
        $spreadsheet = $factory->create();

        // Create schema metadata sheet.
        $schemaSheet = $spreadsheet->createSheet(
            name: $this->schemaSheetName,
            rows: [],
            isAssociative: true
        );

        // Initialize schema sheet with header row.
        $schemaSheet->addRow([
            'type' => 'type',
            'name' => 'name',
            'properties' => 'properties',
        ]);

        // Add schema metadata.
        $schemaSheet->addRow([
            'type' => 'metadata',
            'name' => 'schema',
            'properties' => [
                'name' => $schema->getName(),
                'tables_count' => count($schema->getTables()),
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        // Process all tables.
        foreach ($schema->getTables() as $table) {
            // Add table metadata to schema sheet.
            $this->addTableToSchemaSheet($schemaSheet, $table);

            // Create data sheet for table contents.
            $this->createTableDataSheet($spreadsheet, $table);
        }

        return $spreadsheet;
    }

    /**
     * Add table metadata to the schema sheet.
     *
     * @param SheetInterface $schemaSheet The schema sheet.
     * @param TableInterface $table The table to process.
     */
    private function addTableToSchemaSheet(
        SheetInterface $schemaSheet,
        TableInterface $table
    ): void {
        $tableName = $table->getName();

        // Add table entry.
        $schemaSheet->addRow([
            'type' => 'table',
            'name' => $tableName,
            'properties' => [
                'primary_key' => $table->getPrimaryKey(),
            ],
        ]);

        // Add columns.
        foreach ($table->getColumns() as $column) {
            $this->addColumnToSchemaSheet($schemaSheet, $table, $column);
        }

        // Add indexes.
        foreach ($table->getIndexes() as $index) {
            $this->addIndexToSchemaSheet($schemaSheet, $table, $index);
        }

        // Add foreign keys.
        foreach ($table->getForeignKeys() as $foreignKey) {
            $this->addForeignKeyToSchemaSheet($schemaSheet, $table, $foreignKey);
        }
    }

    /**
     * Add column metadata to the schema sheet.
     *
     * @param SheetInterface $schemaSheet The schema sheet.
     * @param TableInterface $table The table the column belongs to.
     * @param ColumnInterface $column The column to process.
     */
    private function addColumnToSchemaSheet(
        SheetInterface $schemaSheet,
        TableInterface $table,
        ColumnInterface $column
    ): void {
        $properties = [
            'type' => $column->getType(),
            'nullable' => $column->isNullable(),
        ];

        // Add optional properties if set.
        if ($column->getLength() !== null) {
            $properties['length'] = $column->getLength();
        }

        if ($column->getPrecision() !== null) {
            $properties['precision'] = $column->getPrecision();

            if ($column->getScale() !== null) {
                $properties['scale'] = $column->getScale();
            }
        }

        if ($column->getDefault() !== null) {
            $properties['default'] = $column->getDefault();
        }

        // Check if this column is part of the primary key.
        $isPrimaryKey = in_array($column->getName(), $table->getPrimaryKey(), true);
        if ($isPrimaryKey) {
            $properties['primary_key'] = true;
        }

        $schemaSheet->addRow([
            'type' => 'column',
            'name' => $table->getName() . '.' . $column->getName(),
            'properties' => $properties,
        ]);
    }

    /**
     * Add index metadata to the schema sheet.
     *
     * @param SheetInterface $schemaSheet The schema sheet.
     * @param TableInterface $table The table the index belongs to.
     * @param IndexInterface $index The index to process.
     */
    private function addIndexToSchemaSheet(
        SheetInterface $schemaSheet,
        TableInterface $table,
        IndexInterface $index
    ): void {
        $schemaSheet->addRow([
            'type' => 'index',
            'name' => $table->getName() . '.' . $index->getName(),
            'properties' => [
                'columns' => $index->getColumns(),
                'unique' => $index->isUnique(),
                'flags' => $index->getFlags(),
            ],
        ]);
    }

    /**
     * Add foreign key metadata to the schema sheet.
     *
     * @param SheetInterface $schemaSheet The schema sheet.
     * @param TableInterface $table The table the foreign key belongs to.
     * @param ForeignKeyInterface $foreignKey The foreign key to process.
     */
    private function addForeignKeyToSchemaSheet(
        SheetInterface $schemaSheet,
        TableInterface $table,
        ForeignKeyInterface $foreignKey
    ): void {
        $properties = [
            'local_columns' => $foreignKey->getLocalColumns(),
            'foreign_table' => $foreignKey->getForeignTableName(),
            'foreign_columns' => $foreignKey->getForeignColumns(),
        ];

        if ($foreignKey->getOnDelete() !== null) {
            $properties['on_delete'] = $foreignKey->getOnDelete();
        }

        if ($foreignKey->getOnUpdate() !== null) {
            $properties['on_update'] = $foreignKey->getOnUpdate();
        }

        $schemaSheet->addRow([
            'type' => 'foreign_key',
            'name' => $table->getName() . '.'
                . (
                    $foreignKey->getName()
                    ?? 'fk_' . implode('_', $foreignKey->getLocalColumns())
                )
            ,
            'properties' => $properties,
        ]);
    }

    /**
     * Create a data sheet for a table.
     *
     * @param SpreadsheetInterface $spreadsheet The spreadsheet.
     * @param TableInterface $table The table to create a sheet for.
     */
    private function createTableDataSheet(
        SpreadsheetInterface $spreadsheet,
        TableInterface $table
    ): void {
        $tableName = $table->getName();

        // Create a new sheet for the table.
        $dataSheet = $spreadsheet->createSheet($tableName);

        // Add header row with column names.
        $headerRow = [];
        foreach ($table->getColumns() as $column) {
            $headerRow[] = $column->getName();
        }

        $dataSheet->addRow($headerRow);
    }
}
