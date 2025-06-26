<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From spreadsheets to databases seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Load;

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Extract\Contract\DataSourceInterface;
use Derafu\ETL\Load\Contract\DataTargetInterface;

/**
 * DataTarget is responsible for applying data to a specific target.
 */
final class DataTarget implements DataTargetInterface
{
    /**
     * Constructor.
     *
     * @param DatabaseInterface $database
     */
    public function __construct(
        private readonly DatabaseInterface $database,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function sync(
        DataSourceInterface $source,
        array $options = []
    ): self {
        $this->database->sync($source->database(), $options);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $table, array $rows): int
    {
        return $this->database->load([$table => $rows]);
    }

    /**
     * {@inheritDoc}
     */
    public function database(): DatabaseInterface
    {
        return $this->database;
    }
}
