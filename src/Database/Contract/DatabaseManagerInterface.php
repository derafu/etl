<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Database\Contract;

use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Doctrine\DBAL\Connection as DoctrineConnection;

/**
 * Database manager interface.
 */
interface DatabaseManagerInterface
{
    /**
     * Connect to a database.
     *
     * The $connection can be:
     *
     *   - DoctrineConnection: A Doctrine connection.
     *   - SpreadsheetInterface: A spreadsheet.
     *   - array: A configuration array for Doctrine or Spreadsheet.
     *   - string: A path to a file of a spreadsheet or a string of data. If is
     *     a string, the $options['format'] is required to load the spreadsheet.
     *
     * The $options can be:
     *
     *   - The database options, that can be:
     *     - format: The format of the spreadsheet.
     *     - readOnly: Whether the database is read-only.
     *     - createIfNotExists: Whether to create the database if it does not exist.
     *   - The drop options, that can be:
     *     - dropDatabase: Whether to drop the database before creating it.
     *     - dropTables: Whether to drop the tables before creating them.
     *     - dropData: Whether to drop the data before populating the database.
     *
     * @param SpreadsheetInterface|array|string $connection The connection.
     * @param array $options The options for the database (not only connection).
     * @return DatabaseInterface The database representation.
     */
    public function connect(
        DoctrineConnection|SpreadsheetInterface|array|string $connection,
        array $options = []
    ): DatabaseInterface;
}
