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
use Derafu\ETL\Schema\Enum\IndexType;

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
     * The type of the index.
     *
     * @var IndexType
     */
    private IndexType $type;

    /**
     * Whether the index is clustered.
     *
     * @var bool
     */
    private bool $clustered;

    /**
     * Constructor.
     *
     * @param string $name The index name.
     * @param string[] $columns The column names.
     * @param IndexType $type The index type.
     * @param bool $clustered Whether the index is clustered.
     */
    public function __construct(
        string $name,
        array $columns = [],
        IndexType $type = IndexType::REGULAR,
        bool $clustered = false
    ) {
        $this->name = $name;
        $this->columns = $columns;
        $this->type = $type;
        $this->clustered = $clustered;
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
    public function getType(): IndexType
    {
        return $this->type;
    }

    /**
     * {@inheritDoc}
     */
    public function setType(IndexType $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isUnique(): bool
    {
        return $this->type === IndexType::UNIQUE;
    }

    /**
     * {@inheritDoc}
     */
    public function isClustered(): bool
    {
        return $this->clustered;
    }

    /**
     * {@inheritDoc}
     */
    public function setClustered(bool $clustered): self
    {
        $this->clustered = $clustered;

        return $this;
    }
}
