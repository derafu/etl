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
 * Generates a text representation of a database schema.
 */
final class TextSchemaTarget implements SchemaTargetInterface
{
    /**
     * The character to use for indentation.
     *
     * @var string
     */
    private string $indentChar;

    /**
     * The number of indent characters to use per level.
     *
     * @var int
     */
    private int $indentSize;

    /**
     * Constructor.
     *
     * @param string $indentChar The character to use for indentation.
     * @param int $indentSize The number of indent characters to use per level.
     */
    public function __construct(string $indentChar = ' ', int $indentSize = 2)
    {
        $this->indentChar = $indentChar;
        $this->indentSize = $indentSize;
    }

    /**
     * {@inheritDoc}
     */
    public function applySchema(SchemaInterface $schema): string
    {
        $output = '';

        if ($schema->getName() !== null) {
            $output .= "SCHEMA: {$schema->getName()}\n\n";
        }

        // Get the list of tables.
        $tables = $schema->getTables();

        // Build detailed output with all table information.
        foreach ($tables as $table) {
            $output .= $this->processTable($table);
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Process a table and return its text representation.
     *
     * @param TableInterface $table The table to process.
     * @return string
     */
    private function processTable(TableInterface $table): string
    {
        $output = "TABLE: {$table->getName()}\n";

        // Primary Key.
        $primaryKey = $table->getPrimaryKey();
        if (!empty($primaryKey)) {
            $output .= $this->indent(1) . "PRIMARY KEY: " . implode(', ', $primaryKey) . "\n";
        }

        // Columns with detailed information.
        $output .= $this->indent(1) . "COLUMNS:\n";
        foreach ($table->getColumns() as $column) {
            $output .= $this->processColumn($column, 2);
        }

        // Indexes.
        $indexes = $table->getIndexes();
        if (!empty($indexes)) {
            $output .= $this->indent(1) . "INDEXES:\n";
            foreach ($indexes as $index) {
                $output .= $this->processIndex($index, 2);
            }
        }

        // Foreign Keys.
        $foreignKeys = $table->getForeignKeys();
        if (!empty($foreignKeys)) {
            $output .= $this->indent(1) . "FOREIGN KEYS:\n";
            foreach ($foreignKeys as $foreignKey) {
                $output .= $this->processForeignKey($foreignKey, 2);
            }
        }

        return $output;
    }

    /**
     * Process a column and return its text representation.
     *
     * @param ColumnInterface $column The column to process.
     * @param int $indentLevel The indentation level.
     * @return string
     */
    private function processColumn(ColumnInterface $column, int $indentLevel): string
    {
        $details = [];
        $details[] = $column->getType();

        if ($column->getLength() !== null) {
            $details[] = "length={$column->getLength()}";
        }

        if ($column->getPrecision() !== null) {
            $precision = "precision={$column->getPrecision()}";
            if ($column->getScale() !== null) {
                $precision .= ",scale={$column->getScale()}";
            }
            $details[] = $precision;
        }

        if (!$column->isNullable()) {
            $details[] = "NOT NULL";
        }

        if ($column->getDefault() !== null) {
            $default = $column->getDefault();
            if (is_string($default)) {
                $default = "'{$default}'";
            }
            $details[] = "default={$default}";
        }

        return $this->indent($indentLevel)
            . "{$column->getName()} (" . implode(', ', $details) . ")\n"
        ;
    }

    /**
     * Process an index and return its text representation.
     *
     * @param IndexInterface $index The index to process.
     * @param int $indentLevel The indentation level.
     * @return string
     */
    private function processIndex(IndexInterface $index, int $indentLevel): string
    {
        $type = $index->isUnique() ? 'UNIQUE INDEX' : 'INDEX';
        $output = $this->indent($indentLevel)
            . "{$index->getName()} ({$type}): "
            . implode(', ', $index->getColumns()) . "\n"
        ;

        if (!empty($index->getFlags())) {
            $output .= $this->indent($indentLevel + 1)
                . "FLAGS: " . implode(', ', $index->getFlags()) . "\n"
            ;
        }

        return $output;
    }

    /**
     * Process a foreign key and return its text representation.
     *
     * @param ForeignKeyInterface $foreignKey The foreign key to process.
     * @param int $indentLevel The indentation level.
     * @return string
     */
    private function processForeignKey(ForeignKeyInterface $foreignKey, int $indentLevel): string
    {
        $name = $foreignKey->getName() ?? 'unnamed';
        $output = $this->indent($indentLevel) . "{$name}: " .
            implode(', ', $foreignKey->getLocalColumns()) .
            " -> {$foreignKey->getForeignTableName()}(" .
            implode(', ', $foreignKey->getForeignColumns()) . ")\n";

        if ($foreignKey->getOnDelete() !== null) {
            $output .= $this->indent($indentLevel + 1)
                . "ON DELETE: {$foreignKey->getOnDelete()}\n"
            ;
        }

        if ($foreignKey->getOnUpdate() !== null) {
            $output .= $this->indent($indentLevel + 1)
                . "ON UPDATE: {$foreignKey->getOnUpdate()}\n"
            ;
        }

        return $output;
    }

    /**
     * Generate an indentation string for the given level.
     *
     * @param int $level The indentation level.
     * @return string
     */
    private function indent(int $level): string
    {
        return str_repeat($this->indentChar, $level * $this->indentSize);
    }
}
