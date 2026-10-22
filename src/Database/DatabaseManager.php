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

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Database\Contract\DatabaseManagerInterface;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\Spreadsheet\Contract\SpreadsheetFactoryInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Spreadsheet\SpreadsheetCaster;
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetFactory;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Doctrine\DBAL\Connection as DoctrineConnection;

/**
 * Database manager.
 *
 * This class is responsible for managing the database connection to a PDO
 * connection or a spreadsheet. Last one is the default, and can be a file or a
 * string of data.
 */
final class DatabaseManager implements DatabaseManagerInterface
{
    /**
     * The spreadsheet factory.
     *
     * @var SpreadsheetFactoryInterface
     */
    private SpreadsheetFactoryInterface $spreadsheetFactory;

    /**
     * Constructor.
     *
     * @param SpreadsheetFactoryInterface $spreadsheetFactory The spreadsheet factory.
     */
    public function __construct(
        ?SpreadsheetFactoryInterface $spreadsheetFactory = null
    ) {
        $this->spreadsheetFactory = $spreadsheetFactory ?? new SpreadsheetFactory();
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        DoctrineConnection|SpreadsheetInterface|array|string $connection,
        array $options = []
    ): DatabaseInterface {
        // If the source is an array, merge it with the options and use it as
        // the source.
        if (is_array($connection)) {
            $options = array_merge($connection, $options);
            $connection = null;
        }

        // Resolve the options.
        $options = $this->resolveOptions($options);

        // If the source is a Doctrine connection or the doctrine option is set,
        // connect to the Doctrine database.
        if ($connection instanceof DoctrineConnection) {
            $options['doctrine'] = $connection;
        }
        if (!empty($options['doctrine'])) {
            return $this->connectToDoctrine($options);
        }

        // If the source is a SpreadsheetInterface, connect to the spreadsheet
        // database.
        if ($connection instanceof SpreadsheetInterface) {
            $options['spreadsheet'] = $connection;
        }
        if (!empty($options['spreadsheet'])) {
            return $this->connectToSpreadsheet($options);
        }

        // If the source is a string, resolve to a file or data.
        if (is_string($connection)) {
            if (!empty($options['format']) && !file_exists($connection)) {
                $options['data'] = $connection;
            } else {
                $options['file'] = $connection;
            }
        }

        // If the source is a file, connect to the file.
        if (!empty($options['file'])) {
            return $this->connectToFile($options);
        }

        // If the source is data, connect to the data.
        if (!empty($options['data'])) {
            return $this->connectToData($options);
        }

        // If the source is not valid, throw an error.
        throw new InvalidArgumentException(
            'The source is not valid for creating a connection to a database'
        );
    }

    /**
     * Resolve the options.
     *
     * @param array<array-key, mixed> $options The options for the database (not only connection).
     * @return array<array-key, mixed> The resolved options.
     */
    private function resolveOptions(array $options): array
    {
        $options = array_merge([
            // Connection options.
            'doctrine' => null,
            'spreadsheet' => null,
            'file' => null,
            'data' => null,

            // Database options.
            'format' => null,
            'readOnly' => false,
            'createIfNotExists' => false,

            // Drop options.
            'dropDatabase' => false,
            'dropTables' => false,
            'dropData' => false,
        ], $options);

        // If the format is sqlite, set the doctrine options.
        if ($options['format'] === 'sqlite') {
            $options['doctrine'] = [
                'driver' => 'pdo_sqlite',
            ];
            if (!empty($options['file'])) {
                $options['doctrine']['path'] = $options['file'];
            }
        }

        return $options;
    }

    /**
     * Connect to a Doctrine connection.
     *
     * @param array<array-key, mixed> $options The options for the database (not only connection).
     * @return DatabaseInterface The database representation.
     */
    private function connectToDoctrine(array $options): DatabaseInterface
    {
        $doctrine = $options['doctrine'];
        if (!$doctrine instanceof DoctrineConnection && !is_array($doctrine) && !is_string($doctrine)) {
            throw new InvalidArgumentException([
                'Option "{option}" must be a Doctrine connection, an array or a string; {type} given.',
                'option' => 'doctrine',
                'type' => get_debug_type($doctrine),
            ]);
        }

        return new DoctrineDatabase($doctrine, $options);
    }

    /**
     * Connect to a file.
     *
     * The file must be valid for the spreadsheet format handler.
     *
     * @param array<array-key, mixed> $options The options for the database (not only connection).
     * @return DatabaseInterface The database representation.
     */
    private function connectToFile(array $options): DatabaseInterface
    {
        $file = $this->requireString($options['file'], 'file');
        $format = $this->requireString($options['format'] ?? pathinfo($file, PATHINFO_EXTENSION), 'format');

        $exists = file_exists($file);
        $this->validateFile($file, $options);

        $handler = $this->spreadsheetFactory->createFormatHandler($file, $format);

        if ($exists) {
            $options['spreadsheet'] = $handler->loadFromFile($file);
            // Serialized schema properties need the central caster when a
            // document is reopened. Keep business-cell values unchanged here.
            $metadata = $options['spreadsheet']->getSheet('__schema');
            if ($metadata !== null) {
                $schemaDocument = $this->spreadsheetFactory->create();
                $schemaDocument->addSheet($metadata->toAssociative());
                $schemaDocument = (new SpreadsheetCaster())->castAfterLoad($schemaDocument);
                foreach ($schemaDocument->getSheets() as $sheet) {
                    $options['spreadsheet']->addSheet($sheet);
                }
            }
        } else {
            // Let the format handler create a valid document, rather than
            // writing an empty string and attempting to parse it as that format.
            // XLSX requires a sheet: an empty ETL schema is meaningful metadata,
            // not a placeholder business table, and remains after data is loaded.
            $options['spreadsheet'] = $format === 'xlsx'
                ? (new SpreadsheetSchemaTarget())->applySchema(new Schema())
                : $this->spreadsheetFactory->create();
            (new SpreadsheetDumper($this->spreadsheetFactory))->dumpToFile($options['spreadsheet'], $file, $format);
        }

        return $this->connectToSpreadsheet($options);
    }

    /**
     * Connect to data.
     *
     * The data must be valid for the spreadsheet format handler. And the
     * format must be provided in the $options['format'] key.
     *
     * @param array<array-key, mixed> $options The options for the database (not only connection).
     * @return DatabaseInterface The database representation.
     */
    private function connectToData(array $options): DatabaseInterface
    {
        $data = $this->requireString($options['data'], 'data');
        $format = $options['format']
            ?? throw new InvalidArgumentException(
                'Format is required for data connection.'
            )
        ;

        $format = $this->requireString($format, 'format');

        $handler = $this->spreadsheetFactory->createFormatHandler(
            'dummy.' . $format,
            $format
        );

        $options['spreadsheet'] = $handler->loadFromString($data);

        return $this->connectToSpreadsheet($options);
    }

    /**
     * Connect to a spreadsheet.
     *
     * @param array<array-key, mixed> $options The options for the database (not only connection).
     * @return DatabaseInterface The database representation.
     */
    private function connectToSpreadsheet(array $options): DatabaseInterface
    {
        $spreadsheet = $options['spreadsheet']
            ?? $this->spreadsheetFactory->create()
        ;

        if (!$spreadsheet instanceof SpreadsheetInterface) {
            throw new InvalidArgumentException([
                'Option "{option}" must be a SpreadsheetInterface instance; {type} given.',
                'option' => 'spreadsheet',
                'type' => get_debug_type($spreadsheet),
            ]);
        }

        unset($options['spreadsheet']);

        return new SpreadsheetDatabase($spreadsheet, $options);
    }

    /**
     * Validate the file.
     *
     * If creation is allowed for a missing file, prepare its directory. The
     * caller creates the document using its format handler.
     *
     * If the file exists, it will be checked if it is readable and writable.
     *
     * @param string $file The file to validate.
     * @param array<array-key, mixed> $options The options for the database (not only connection).
     */
    private function validateFile(string $file, array $options): void
    {
        // Check file exists, create it if allowed or throw an error.
        if (!file_exists($file)) {
            // If the file does not exist and creation is disabled, throw an error.
            if (!$options['createIfNotExists']) {
                throw new InvalidArgumentException([
                    'File does not exist and creation is disabled for this file: {file}',
                    'file' => $file,
                ]);
            }

            // Check target directory exists or can be created.
            $directory = dirname($file);
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                throw new InvalidArgumentException([
                    'Target directory does not exist and cannot be created: {file}',
                    'file' => $file,
                ]);
            }

            // Check if the directory is writable.
            if (!is_writable($directory)) {
                throw new InvalidArgumentException([
                    'Directory {directory} is not writable for creating an empty file: {file}',
                    'directory' => $directory,
                    'file' => basename($file),
                ]);
            }

            return;
        }

        // Check file is readable.
        if (!is_readable($file)) {
            throw new InvalidArgumentException([
                'File is not readable: {file}',
                'file' => $file,
            ]);
        }

        // Check file is writable if database is not read-only.
        if (!$options['readOnly'] && !is_writable($file)) {
            throw new InvalidArgumentException([
                'File is not writable: {file}',
                'file' => $file,
            ]);
        }
    }

    private function requireString(mixed $value, string $option): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException([
                'Option "{option}" must be a string; {type} given.',
                'option' => $option,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }
}
