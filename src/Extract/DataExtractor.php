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
use Derafu\ETL\Database\Contract\DatabaseManagerInterface;
use Derafu\ETL\Database\DatabaseManager;
use Derafu\ETL\Extract\Contract\DataExtractorInterface;
use Derafu\ETL\Extract\Contract\DataSourceInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;

/**
 * DataExtractor is responsible for creating a DataSource from a source
 * configuration and extracting data from it.
 */
final class DataExtractor implements DataExtractorInterface
{
    /**
     * Constructor.
     *
     * @param DatabaseManagerInterface $databaseManager
     */
    public function __construct(
        private readonly DatabaseManagerInterface $databaseManager = new DatabaseManager(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function createSource(
        DatabaseInterface|SpreadsheetInterface|array|string $source
    ): DataSourceInterface {
        if (!$source instanceof DatabaseInterface) {
            $source = $this->databaseManager->connect($source);
        }

        return new DataSource($source);
    }

    /**
     * {@inheritDoc}
     */
    public function extract(DataSourceInterface $source): array
    {
        $data = [];

        foreach ($source->getTableNames() as $table) {
            $data[$table] = $source->extractTableData($table);
        }

        return $data;
    }
}
