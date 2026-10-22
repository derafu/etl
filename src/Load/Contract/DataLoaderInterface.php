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
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;

/**
 * DataLoaderInterface is the interface for the DataLoader class.
 */
interface DataLoaderInterface
{
    /**
     * Create a target.
     *
     * The target can be a database, a spreadsheet, an array or a string:
     *
     *   - DatabaseInterface: A database connection.
     *   - SpreadsheetInterface: A spreadsheet.
     *   - array: A configuration array.
     *   - string: A path to a file of a spreadsheet.
     *
     * @param DatabaseInterface|SpreadsheetInterface|array<array-key, mixed>|string $target The target.
     * @return DataTargetInterface The target.
     */
    public function createTarget(
        DatabaseInterface|SpreadsheetInterface|array|string $target
    ): DataTargetInterface;

    /**
     * Load the data into the target.
     *
     * @param DataTargetInterface $target The target.
     * @param array<array-key, mixed> $data Raw table names mapped to row collections.
     * @return int The number of rows loaded.
     * @throws InvalidArgumentException If a table name is empty or its rows are not an array.
     */
    public function load(DataTargetInterface $target, array $data): int;
}
