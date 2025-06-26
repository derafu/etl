<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From spreadsheets to databases seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Extract\Contract;

use Derafu\ETL\Database\Contract\DatabaseInterface;

/**
 * DataSource is responsible for extracting data rows from a specific source.
 *
 * This interface defines the minimum contract for any data source adapter that
 * can extract data for ETL pipeline.
 */
interface DataSourceInterface
{
    /**
     * Get a list of available table names in this data source.
     *
     * @return string[] Array of table names.
     */
    public function getTableNames(): array;

    /**
     * Extract data from the source for a specific table.
     *
     * @param string $table The name of the table to extract data for.
     * @return array An array representing rows of data.
     */
    public function extractTableData(string $table): array;

    /**
     * Get the database.
     *
     * @return DatabaseInterface The database.
     */
    public function database(): DatabaseInterface;
}
