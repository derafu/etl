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
 * ForeignKey represents a foreign key constraint within a database table.
 *
 * This interface defines the minimum contract for working with foreign keys
 * within the Derafu\ETL package, defining relationships between tables.
 */
interface ForeignKeyInterface
{
    /**
     * Get the name of the foreign key constraint.
     *
     * @return string|null The constraint name if set, null otherwise.
     */
    public function getName(): ?string;

    /**
     * Set the name of the foreign key constraint.
     *
     * @param string $name The constraint name.
     * @return self
     */
    public function setName(string $name): self;

    /**
     * Get the local column names involved in this foreign key.
     *
     * @return string[] Array of local column names.
     */
    public function getLocalColumns(): array;

    /**
     * Set the local column names involved in this foreign key.
     *
     * @param string[] $columnNames Array of local column names.
     * @return self
     */
    public function setLocalColumns(array $columnNames): self;

    /**
     * Get the foreign table name this key references.
     *
     * @return string The referenced table name.
     */
    public function getForeignTableName(): string;

    /**
     * Set the foreign table name this key references.
     *
     * @param string $tableName The referenced table name.
     * @return self
     */
    public function setForeignTableName(string $tableName): self;

    /**
     * Get the foreign column names this key references.
     *
     * @return string[] Array of foreign column names.
     */
    public function getForeignColumns(): array;

    /**
     * Set the foreign column names this key references.
     *
     * @param string[] $columnNames Array of foreign column names.
     * @return self
     */
    public function setForeignColumns(array $columnNames): self;

    /**
     * Get the on delete action.
     *
     * @return string|null The on delete action (CASCADE, SET NULL, etc.) or
     * null if not specified.
     */
    public function getOnDelete(): ?string;

    /**
     * Set the on delete action.
     *
     * @param string|null $action The on delete action or null to unset.
     * @return self
     */
    public function setOnDelete(?string $action): self;

    /**
     * Get the on update action.
     *
     * @return string|null The on update action (CASCADE, SET NULL, etc.) or
     * null if not specified.
     */
    public function getOnUpdate(): ?string;

    /**
     * Set the on update action.
     *
     * @param string|null $action The on update action or null to unset.
     * @return self
     */
    public function setOnUpdate(?string $action): self;
}
