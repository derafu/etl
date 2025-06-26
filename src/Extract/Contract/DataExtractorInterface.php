<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Extract\Contract;

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;

/**
 * DataExtractorInterface is the contract for a data extractor.
 */
interface DataExtractorInterface
{
    /**
     * Create a DataSource from a source configuration.
     *
     * The source can be a database, a spreadsheet, an array or a string:
     *
     *   - DatabaseInterface: A database connection.
     *   - SpreadsheetInterface: A spreadsheet.
     *   - array: A configuration array.
     *   - string: A path to a file of a spreadsheet.
     *
     * @param DatabaseInterface|SpreadsheetInterface|array|string $source
     * @return DataSourceInterface
     */
    public function createSource(
        DatabaseInterface|SpreadsheetInterface|array|string $source
    ): DataSourceInterface;

    /**
     * Extract data from the source.
     *
     * @param DataSourceInterface $source
     * @return array<string,array<string,mixed>>
     */
    public function extract(DataSourceInterface $source): array;
}
