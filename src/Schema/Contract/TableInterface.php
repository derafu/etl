<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Schema\Contract;

/**
 * Table represents a database table within a schema.
 *
 * This interface defines the minimum contract for working with database tables
 * within the Derafu\ETL package, providing access to columns, keys and other
 * table metadata.
 */
interface TableInterface
{
    /**
     * Get the table name.
     *
     * @return string The table name.
     */
    public function getName(): string;

    /**
     * Set the table name.
     *
     * @param string $name The table name.
     * @return self
     */
    public function setName(string $name): self;

    /**
     * Get all columns defined in this table.
     *
     * @return ColumnInterface[] An array of columns.
     */
    public function getColumns(): array;

    /**
     * Get a specific column by name.
     *
     * @param string $name The column name to retrieve.
     * @return ColumnInterface|null The column if found, null otherwise.
     */
    public function getColumn(string $name): ?ColumnInterface;

    /**
     * Add a column to the table.
     *
     * @param ColumnInterface $column The column to add.
     * @return self
     */
    public function addColumn(ColumnInterface $column): self;

    /**
     * Check if a column with the given name exists.
     *
     * @param string $name The column name to check.
     * @return bool True if column exists, false otherwise.
     */
    public function hasColumn(string $name): bool;

    /**
     * Get the primary key columns.
     *
     * @return string[] Array of column names that form the primary key.
     */
    public function getPrimaryKey(): array;

    /**
     * Set the primary key.
     *
     * @param string[] $columnNames Array of column names to set as primary key.
     * @return self
     */
    public function setPrimaryKey(array $columnNames): self;

    /**
     * Get all foreign keys defined in this table.
     *
     * @return ForeignKeyInterface[] An array of foreign keys.
     */
    public function getForeignKeys(): array;

    /**
     * Add a foreign key to the table.
     *
     * @param ForeignKeyInterface $foreignKey The foreign key to add.
     * @return self
     */
    public function addForeignKey(ForeignKeyInterface $foreignKey): self;

    /**
     * Get all indexes defined in this table.
     *
     * @return IndexInterface[] An array of indexes.
     */
    public function getIndexes(): array;

    /**
     * Get a specific index by name.
     *
     * @param string $name The index name to retrieve.
     * @return IndexInterface|null The index if found, null otherwise.
     */
    public function getIndex(string $name): ?IndexInterface;

    /**
     * Add an index to the table.
     *
     * @param IndexInterface $index The index to add.
     * @return self
     */
    public function addIndex(IndexInterface $index): self;

    /**
     * Check if an index with the given name exists.
     *
     * @param string $name The index name to check.
     * @return bool True if index exists, false otherwise.
     */
    public function hasIndex(string $name): bool;
}
