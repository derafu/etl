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

use Derafu\ETL\Schema\Enum\IndexType;

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
     * Get the index type.
     *
     * @return IndexType The index type.
     */
    public function getType(): IndexType;

    /**
     * Set the index type.
     *
     * @param IndexType $type The index type.
     * @return self
     */
    public function setType(IndexType $type): self;

    /**
     * Check if the index is clustered.
     *
     * @return bool True if the index is clustered, false otherwise.
     */
    public function isClustered(): bool;

    /**
     * Set whether this index is clustered.
     *
     * @param bool $clustered Whether the index is clustered.
     * @return self
     */
    public function setClustered(bool $clustered): self;
}
