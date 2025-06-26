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
 * Index represents a database index within a table.
 *
 * This interface defines the minimum contract for working with database indexes
 * within the Derafu\ETL package.
 */
interface IndexInterface
{
    /**
     * Get the index name.
     *
     * @return string The index name.
     */
    public function getName(): string;

    /**
     * Set the index name.
     *
     * @param string $name The index name.
     * @return self
     */
    public function setName(string $name): self;

    /**
     * Get the columns that make up this index.
     *
     * @return string[] Array of column names.
     */
    public function getColumns(): array;

    /**
     * Set the columns that make up this index.
     *
     * @param string[] $columnNames Array of column names.
     * @return self
     */
    public function setColumns(array $columnNames): self;

    /**
     * Check if this index is unique.
     *
     * @return bool True if the index is unique, false otherwise.
     */
    public function isUnique(): bool;

    /**
     * Set whether this index is unique.
     *
     * @param bool $unique Whether the index is unique.
     * @return self
     */
    public function setUnique(bool $unique): self;

    /**
     * Get the index flags (fulltext, etc.).
     *
     * @return string[] Array of flags.
     */
    public function getFlags(): array;

    /**
     * Set the index flags.
     *
     * @param string[] $flags Array of flags.
     * @return self
     */
    public function setFlags(array $flags): self;
}
