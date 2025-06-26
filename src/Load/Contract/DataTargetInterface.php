<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Load\Contract;

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Extract\Contract\DataSourceInterface;

/**
 * DataTarget is responsible for applying data to a specific target.
 *
 * This interface defines the minimum contract for any data target adapter that
 * can insert or update data in a target system.
 */
interface DataTargetInterface
{
    /**
     * Sync the target with the source.
     *
     * @param DataSourceInterface $source The source.
     * @param array<string,mixed> $options The options.
     * @return self The target.
     */
    public function sync(
        DataSourceInterface $source,
        array $options = []
    ): self;

    /**
     * Load the given data rows to the specified table.
     *
     * @param string $table The name of the table to insert/update data in.
     * @param array $rows An array representing rows of data.
     * @return int Number of affected rows.
     */
    public function load(string $table, array $rows): int;

    /**
     * Get the database.
     *
     * @return DatabaseInterface The database.
     */
    public function database(): DatabaseInterface;
}
