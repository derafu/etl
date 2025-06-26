<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Schema;

use Derafu\ETL\Schema\Contract\IndexInterface;

/**
 * Implementation of a database index.
 */
final class Index implements IndexInterface
{
    /**
     * The name of the index.
     *
     * @var string
     */
    private string $name;

    /**
     * The columns that make up the index.
     *
     * @var string[]
     */
    private array $columns = [];

    /**
     * Whether the index is unique.
     *
     * @var bool
     */
    private bool $unique = false;

    /**
     * Additional flags for the index.
     *
     * @var string[]
     */
    private array $flags = [];

    /**
     * Constructor.
     *
     * @param string $name The index name.
     * @param string[] $columns The column names.
     * @param bool $unique Whether the index is unique.
     * @param string[] $flags Additional flags for the index.
     */
    public function __construct(
        string $name,
        array $columns = [],
        bool $unique = false,
        array $flags = []
    ) {
        $this->name = $name;
        $this->columns = $columns;
        $this->unique = $unique;
        $this->flags = $flags;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * {@inheritDoc}
     */
    public function setColumns(array $columnNames): self
    {
        $this->columns = $columnNames;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * {@inheritDoc}
     */
    public function setUnique(bool $unique): self
    {
        $this->unique = $unique;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * {@inheritDoc}
     */
    public function setFlags(array $flags): self
    {
        $this->flags = $flags;

        return $this;
    }
}
