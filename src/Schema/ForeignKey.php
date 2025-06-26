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

use Derafu\ETL\Schema\Contract\ForeignKeyInterface;

/**
 * Implementation of a database foreign key.
 */
final class ForeignKey implements ForeignKeyInterface
{
    /**
     * The name of the foreign key.
     *
     * @var string|null
     */
    private ?string $name = null;

    /**
     * The local columns of the foreign key.
     *
     * @var string[]
     */
    private array $localColumns = [];

    /**
     * The foreign table name of the foreign key.
     *
     * @var string
     */
    private string $foreignTableName;

    /**
     * The foreign columns of the foreign key.
     *
     * @var string[]
     */
    private array $foreignColumns = [];

    /**
     * The action to perform on delete.
     *
     * @var string|null
     */
    private ?string $onDelete = null;

    /**
     * The action to perform on update.
     *
     * @var string|null
     */
    private ?string $onUpdate = null;

    /**
     * Constructor.
     *
     * @param string $foreignTableName The foreign table name.
     * @param string[] $localColumns The local column names.
     * @param string[] $foreignColumns The foreign column names.
     * @param string|null $name The constraint name
     */
    public function __construct(
        string $foreignTableName,
        array $localColumns,
        array $foreignColumns,
        ?string $name = null
    ) {
        $this->foreignTableName = $foreignTableName;
        $this->localColumns = $localColumns;
        $this->foreignColumns = $foreignColumns;
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
    public function getLocalColumns(): array
    {
        return $this->localColumns;
    }

    /**
     * {@inheritDoc}
     */
    public function setLocalColumns(array $columnNames): self
    {
        $this->localColumns = $columnNames;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getForeignTableName(): string
    {
        return $this->foreignTableName;
    }

    /**
     * {@inheritDoc}
     */
    public function setForeignTableName(string $tableName): self
    {
        $this->foreignTableName = $tableName;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getForeignColumns(): array
    {
        return $this->foreignColumns;
    }

    /**
     * {@inheritDoc}
     */
    public function setForeignColumns(array $columnNames): self
    {
        $this->foreignColumns = $columnNames;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getOnDelete(): ?string
    {
        return $this->onDelete;
    }

    /**
     * {@inheritDoc}
     */
    public function setOnDelete(?string $action): self
    {
        $this->onDelete = $action;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
    }

    /**
     * {@inheritDoc}
     */
    public function setOnUpdate(?string $action): self
    {
        $this->onUpdate = $action;

        return $this;
    }
}
