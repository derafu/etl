<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Extract;

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Extract\Contract\DataSourceInterface;
use Derafu\ETL\Schema\Contract\TableInterface;

/**
 * DataSource is responsible for extracting data from a specific source.
 */
final class DataSource implements DataSourceInterface
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
    public function getTableNames(): array
    {
        return array_map(
            fn (TableInterface $table) => $table->getName(),
            $this->database->schema()->getTables()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function extractTableData(string $table): array
    {
        return $this->database->data(['table' => $table]);
    }

    /**
     * {@inheritDoc}
     */
    public function database(): DatabaseInterface
    {
        return $this->database;
    }
}
