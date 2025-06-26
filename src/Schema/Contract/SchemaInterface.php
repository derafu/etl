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
 * Schema represents a complete database schema structure.
 *
 * This interface defines the minimum contract for working with database schemas
 * within the Derafu\ETL package, regardless of the actual source or target
 * of the schema (spreadsheet, Doctrine DBAL, etc.).
 */
interface SchemaInterface
{
    /**
     * Get the schema name.
     *
     * @return string|null The schema name if set, null otherwise.
     */
    public function getName(): ?string;

    /**
     * Set the schema name.
     *
     * @param string $name The schema name.
     * @return self
     */
    public function setName(string $name): self;

    /**
     * Get the list of tables in this schema.
     *
     * @return TableInterface[]
     */
    public function getTables(): array;

    /**
     * Get a specific table by name.
     *
     * @param string $name The table name to retrieve.
     * @return TableInterface|null The table if found, null otherwise.
     */
    public function getTable(string $name): ?TableInterface;

    /**
     * Add a table to the schema.
     *
     * @param TableInterface $table The table to add.
     * @return self
     */
    public function addTable(TableInterface $table): self;

    /**
     * Check if a table with the given name exists.
     *
     * @param string $name The table name to check.
     * @return bool True if table exists, false otherwise.
     */
    public function hasTable(string $name): bool;
}
