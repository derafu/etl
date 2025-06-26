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
 * Column represents a database column within a table.
 *
 * This interface defines the minimum contract for working with database columns
 * within the Derafu\ETL package, including type information and constraints.
 */
interface ColumnInterface
{
    /**
     * Get the column name.
     *
     * @return string The column name.
     */
    public function getName(): string;

    /**
     * Set the column name.
     *
     * @param string $name The column name.
     * @return self
     */
    public function setName(string $name): self;

    /**
     * Get the column type.
     *
     * @return string The column type (string, integer, etc.).
     */
    public function getType(): string;

    /**
     * Set the column type.
     *
     * @param string $type The column type.
     * @return self
     */
    public function setType(string $type): self;

    /**
     * Check if the column can be null.
     *
     * @return bool True if the column allows null values, false otherwise.
     */
    public function isNullable(): bool;

    /**
     * Set whether the column can be null.
     *
     * @param bool $nullable Whether the column allows null values.
     * @return self
     */
    public function setNullable(bool $nullable): self;

    /**
     * Get the column's default value, if any.
     *
     * @return mixed The default value or null if no default.
     */
    public function getDefault();

    /**
     * Set the column's default value.
     *
     * @param mixed $default The default value.
     * @return self
     */
    public function setDefault($default): self;

    /**
     * Get the maximum length for string/binary columns.
     *
     * @return int|null The length or null if not applicable.
     */
    public function getLength(): ?int;

    /**
     * Set the maximum length for string/binary columns.
     *
     * @param int|null $length The length or null if not applicable.
     * @return self
     */
    public function setLength(?int $length): self;

    /**
     * Get the precision for decimal/numeric columns.
     *
     * @return int|null The precision or null if not applicable.
     */
    public function getPrecision(): ?int;

    /**
     * Set the precision for decimal/numeric columns.
     *
     * @param int|null $precision The precision or null if not applicable.
     * @return self
     */
    public function setPrecision(?int $precision): self;

    /**
     * Get the scale for decimal/numeric columns.
     *
     * @return int|null The scale or null if not applicable.
     */
    public function getScale(): ?int;

    /**
     * Set the scale for decimal/numeric columns.
     *
     * @param int|null $scale The scale or null if not applicable.
     * @return self
     */
    public function setScale(?int $scale): self;
}
