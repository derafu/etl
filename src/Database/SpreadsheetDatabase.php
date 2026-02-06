<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Database;

use Derafu\ETL\Database\Abstract\AbstractDatabase;
use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\SchemaSourceInterface;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\Spreadsheet\Contract\SpreadsheetDumperInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetLoaderInterface;
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetLoader;
use InvalidArgumentException;

/**
 * Database implementation for spreadsheets.
 */
final class SpreadsheetDatabase extends AbstractDatabase implements DatabaseInterface
{
    /**
     * Constructor.
     *
     * @param SpreadsheetInterface $spreadsheet The spreadsheet.
     * @param array $options The options for the database (not only for spreadsheet).
     */
    public function __construct(
        SpreadsheetInterface $spreadsheet,
        array $options = [],
        private ?SchemaSourceInterface $schemaSource = null,
        private ?SpreadsheetLoaderInterface $loader = null,
        private ?SpreadsheetDumperInterface $dumper = null
    ) {
        foreach ($spreadsheet->getSheets() as $sheet) {
            $spreadsheet->addSheet($sheet->toAssociative());
        }

        parent::__construct($spreadsheet, $options);

        $this->schemaSource = $schemaSource ?? new SpreadsheetSchemaSource();
        $this->loader = $loader ?? new SpreadsheetLoader();
        $this->dumper = $dumper ?? new SpreadsheetDumper();
    }

    /**
     * {@inheritDoc}
     */
    public function dump(array $options = []): string
    {
        $format = $options['format'] ?? $this->options['format'] ?? 'xlsx';

        return $this->dumper->dumpToString($this->connection, $format);
    }

    /**
     * {@inheritDoc}
     */
    public function save(string $file, array $options = []): string
    {
        $format = pathinfo($file, PATHINFO_EXTENSION);

        $this->dumper->dumpToFile($this->connection, $file, $format);

        return $file;
    }

    /**
     * {@inheritDoc}
     */
    public function data(array $options = []): array
    {
        $table = $options['table'] ?? null;

        if ($table === null) {
            $tables = $this->connection->toArray();
        } else {
            $tables = [$table => $this->connection->getSheet($table)->toArray()];
        }

        $data = [];
        foreach ($tables as $name => $info) {
            $data[$name] = $info['rows'];
        }

        return $table === null ? $data : $data[$table];
    }

    /**
     * {@inheritDoc}
     */
    public function spreadsheet(array $options = []): SpreadsheetInterface
    {
        return $this->connection;
    }

    /**
     * {@inheritDoc}
     */
    public function sync(
        DatabaseInterface $source,
        array $options = []
    ): self {
        // Nothing to do, because when the data is loaded from a spreadsheet,
        // the schema will be also loaded.

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function load(
        string|array|SpreadsheetInterface|DatabaseInterface $source,
        array $options = []
    ): int {
        $rowsLoaded = parent::load($source, $options);

        unset($this->schema);

        return $rowsLoaded;
    }

    /**
     * {@inheritDoc}
     */
    protected function createSchema(): SchemaInterface
    {
        return $this->schemaSource->extractSchema($this->connection);
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromDump(string $source, array $options = []): int
    {
        // Get the file format of the source dump. Required to load the dump.
        $format = $options['format'] ?? throw new InvalidArgumentException(
            'Format is required for loading from dump in a spreadsheet database.'
        );

        // Load the source spreadsheet from the dump.
        $sourceSpreadsheet = $this->loader->loadFromString($source, $format);

        // Load the source spreadsheet into the current connection.
        return $this->load($sourceSpreadsheet, $options);
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromArray(array $source, array $options = []): int
    {
        // TODO: Implement loadFromArray() method.
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromDatabase(
        DatabaseInterface $source,
        array $options = []
    ): int {
        // Get the source spreadsheet from the source database.
        $sourceSpreadsheet = $source->spreadsheet();

        // Convert the sheets of the source spreadsheet to associative arrays.
        foreach ($sourceSpreadsheet->getSheets() as $sheet) {
            $sourceSpreadsheet->addSheet($sheet->toAssociative());
        }

        // Drop the database if requested. This is achieved by replacing the
        // current connection with the source spreadsheet.
        // This method keeps nothing from the current connection.
        $dropDatabase = $options['dropDatabase'] ?? false;
        $dropTables = $options['dropTables'] ?? false;
        if ($dropDatabase || $dropTables) {
            $this->connection = $sourceSpreadsheet;
            return 0;
        }

        // This will create a new connection with a new spreadsheet that will
        // keep only the schema from the loaded $source.
        $dropData = $options['dropData'] ?? false;
        $schemaSheetName = $options['schemaSheetName'] ?? '__schema';
        if ($dropData) {
            $schemaTarget = new SpreadsheetSchemaTarget($schemaSheetName);
            $this->connection = $schemaTarget->applySchema($source->schema());
            return 0;
        }

        // Nothing to drop. We keep the current connection and load the data.
        // TODO: Implement load without dropping the data.
        // It must load each sheet without dropping the current data,
        // just add or update the data.
        // WARNING: For NOW, we just load the data from the source spreadsheet.
        // And lost the current data.
        $this->connection = $sourceSpreadsheet;

        return 0;
    }
}
