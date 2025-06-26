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

use Derafu\ETL\Schema\Contract\ColumnInterface;

/**
 * Implementation of a database column.
 */
final class Column implements ColumnInterface
{
    /**
     * The name of the column.
     *
     * @var string
     */
    private string $name;

    /**
     * The type of the column.
     *
     * @var string
     */
    private string $type;

    /**
     * Whether the column can be null.
     *
     * @var bool
     */
    private bool $nullable = true;

    /**
     * The default value of the column.
     *
     * @var mixed
     */
    private $default = null;

    /**
     * The length of the column.
     *
     * @var int|null
     */
    private ?int $length = null;

    /**
     * The precision of the column.
     *
     * @var int|null
     */
    private ?int $precision = null;

    /**
     * The scale of the column.
     *
     * @var int|null
     */
    private ?int $scale = null;

    /**
     * Constructor.
     *
     * @param string $name The column name.
     * @param string $type The column type.
     */
    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
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
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritDoc}
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * {@inheritDoc}
     */
    public function setNullable(bool $nullable): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getDefault()
    {
        return $this->default;
    }

    /**
     * {@inheritDoc}
     */
    public function setDefault($default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getLength(): ?int
    {
        return $this->length;
    }

    /**
     * {@inheritDoc}
     */
    public function setLength(?int $length): self
    {
        $this->length = $length;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    /**
     * {@inheritDoc}
     */
    public function setPrecision(?int $precision): self
    {
        $this->precision = $precision;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getScale(): ?int
    {
        return $this->scale;
    }

    /**
     * {@inheritDoc}
     */
    public function setScale(?int $scale): self
    {
        $this->scale = $scale;

        return $this;
    }
}
