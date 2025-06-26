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

/**
 * Generates a Markdown representation of a database schema.
 */
final class MarkdownSchemaTarget implements SchemaTargetInterface
{
    /**
     * {@inheritDoc}
     */
    public function applySchema(SchemaInterface $schema): string
    {
        $output = '';

        // Add schema title.
        if ($schema->getName() !== null) {
            $output .= "# Schema: {$schema->getName()}\n\n";
        } else {
            $output .= "# Database Schema\n\n";
        }

        // Add table of contents.
        $output .= "## Table of Contents\n\n";
        foreach ($schema->getTables() as $table) {
            $tableName = $table->getName();
            // Create a link-friendly version of the table name.
            $tableLink = strtolower(str_replace('_', '-', $tableName));
            $output .= "- [Table: {$tableName}](#table-{$tableLink})\n";
        }
        $output .= "\n";

        // Process each table.
        foreach ($schema->getTables() as $table) {
            $output .= $this->processTable($table);
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Process a table and return its Markdown representation.
     *
     * @param TableInterface $table The table to process.
     * @return string
     */
    private function processTable(TableInterface $table): string
    {
        $tableName = $table->getName();
        $tableLink = strtolower(str_replace('_', '-', $tableName));

        $output = "## Table: {$tableName} {#table-{$tableLink}}\n\n";

        // Table description (if we had one).
        // $output .= "Description goes here.\n\n";

        // Columns section.
        $output .= "### Columns\n\n";
        $output .= "| Column | Type | Attributes | Description |\n";
        $output .= "|--------|------|------------|-------------|\n";

        foreach ($table->getColumns() as $column) {
            $output .= $this->processColumnRow($column, $table);
        }
        $output .= "\n";

        // Primary key section.
        $primaryKey = $table->getPrimaryKey();
        if (!empty($primaryKey)) {
            $output .= "### Primary Key\n\n";
            $output .= "- Columns: `" . implode("`, `", $primaryKey) . "`\n\n";
        }

        // Indexes section.
        $indexes = $table->getIndexes();
        if (!empty($indexes)) {
            $output .= "### Indexes\n\n";
            $output .= "| Name | Columns | Type | Flags |\n";
            $output .= "|------|---------|------|-------|\n";

            foreach ($indexes as $index) {
                $output .= $this->processIndexRow($index);
            }
            $output .= "\n";
        }

        // Foreign keys section.
        $foreignKeys = $table->getForeignKeys();
        if (!empty($foreignKeys)) {
            $output .= "### Foreign Keys\n\n";
            $output .= "| Name | Columns | References | On Delete | On Update |\n";
            $output .= "|------|---------|------------|-----------|------------|\n";

            foreach ($foreignKeys as $foreignKey) {
                $output .= $this->processForeignKeyRow($foreignKey);
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Process a column and return its Markdown table row.
     *
     * @param ColumnInterface $column The column to process.
     * @param TableInterface $table The table this column belongs to.
     * @return string
     */
    private function processColumnRow(ColumnInterface $column, TableInterface $table): string
    {
        $name = $column->getName();
        $isPK = in_array($name, $table->getPrimaryKey(), true);

        // Format column name.
        $colName = $isPK ? "**{$name}**" : $name;

        // Format column type.
        $type = $column->getType();
        if ($column->getLength() !== null) {
            $type .= "({$column->getLength()})";
        } elseif ($column->getPrecision() !== null) {
            $precision = (string) $column->getPrecision();
            if ($column->getScale() !== null) {
                $precision .= ", " . $column->getScale();
            }
            $type .= "({$precision})";
        }

        // Format attributes.
        $attributes = [];
        if ($isPK) {
            $attributes[] = "PRIMARY KEY";
        }
        if (!$column->isNullable()) {
            $attributes[] = "NOT NULL";
        }
        if ($column->getDefault() !== null) {
            $default = $column->getDefault();
            if (is_string($default)) {
                $default = "'{$default}'";
            }
            $attributes[] = "DEFAULT: {$default}";
        }

        // Format description (placeholder for now).
        $description = "";

        return "| {$colName} | {$type} | " . implode("<br>", $attributes) . " | {$description} |\n";
    }

    /**
     * Process an index and return its Markdown table row.
     *
     * @param IndexInterface $index The index to process.
     * @return string
     */
    private function processIndexRow(IndexInterface $index): string
    {
        $name = $index->getName();
        $columns = "`" . implode("`, `", $index->getColumns()) . "`";
        $type = $index->isUnique() ? "UNIQUE" : "INDEX";
        $flags = implode(", ", $index->getFlags());

        return "| {$name} | {$columns} | {$type} | {$flags} |\n";
    }

    /**
     * Process a foreign key and return its Markdown table row.
     *
     * @param ForeignKeyInterface $foreignKey The foreign key to process.
     * @return string
     */
    private function processForeignKeyRow(ForeignKeyInterface $foreignKey): string
    {
        $name = $foreignKey->getName() ?? "unnamed";
        $columns = "`" . implode("`, `", $foreignKey->getLocalColumns()) . "`";
        $references = "`{$foreignKey->getForeignTableName()}` (`" .
            implode("`, `", $foreignKey->getForeignColumns()) . "`)";
        $onDelete = $foreignKey->getOnDelete() ?? "N/A";
        $onUpdate = $foreignKey->getOnUpdate() ?? "N/A";

        return "| {$name} | {$columns} | {$references} | {$onDelete} | {$onUpdate} |\n";
    }
}
