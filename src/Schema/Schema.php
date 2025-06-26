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

use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\TableInterface;

/**
 * Implementation of a database schema.
 */
final class Schema implements SchemaInterface
{
    /**
     * The name of the schema.
     *
     * @var string|null
     */
    private ?string $name = null;

    /**
     * The tables of the schema.
     *
     * @var TableInterface[]
     */
    private array $tables = [];

    /**
     * Constructor.
     *
     * @param string|null $name The schema name.
     */
    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): ?string
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
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * {@inheritDoc}
     */
    public function getTable(string $name): ?TableInterface
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function addTable(TableInterface $table): self
    {
        $this->tables[$table->getName()] = $table;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }
}
