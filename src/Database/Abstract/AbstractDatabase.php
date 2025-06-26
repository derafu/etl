<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Database\Abstract;

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use InvalidArgumentException;

/**
 * Abstract database class.
 */
abstract class AbstractDatabase implements DatabaseInterface
{
    /**
     * The database connection.
     *
     * Example: PDO instance, SpreadsheetInterface instance, etc.
     *
     * @var mixed
     */
    protected mixed $connection;

    /**
     * The database options.
     *
     * @var array
     */
    protected array $options;

    /**
     * The database schema.
     *
     * @var SchemaInterface
     */
    protected SchemaInterface $schema;

    /**
     * Constructor.
     *
     * @param mixed $connection The database connection.
     * @param array $options The database options.
     */
    public function __construct(mixed $connection, array $options)
    {
        $this->connection = $connection;
        $this->options = $options;
    }

    /**
     * {@inheritDoc}
     */
    public function connection(): mixed
    {
        return $this->connection;
    }

    /**
     * {@inheritDoc}
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * {@inheritDoc}
     */
    public function schema(): SchemaInterface
    {
        if (!isset($this->schema)) {
            $this->schema = $this->createSchema();
        }

        return $this->schema;
    }

    /**
     * {@inheritDoc}
     */
    public function save(string $file, array $options = []): string
    {
        file_put_contents($file, $this->dump($options));

        return $file;
    }

    /**
     * {@inheritDoc}
     */
    public function load(
        string|array|SpreadsheetInterface|DatabaseInterface $source,
        array $options = []
    ): int {
        // If the source is a string, it could be SQL dump or a serialized
        // representation of the dumped database. Each loadFromDump() method
        // must implement the logic to load the data from the dump.
        if (is_string($source)) {
            return $this->loadFromDump($source, $options);
        }

        // If the source is an array, treat it as data directly.
        if (is_array($source)) {
            return $this->loadFromArray($source, $options);
        }

        // If the source is a spreadsheet, convert it first to a temporary
        // spreadsheet database and then load the data from it.
        if ($source instanceof SpreadsheetInterface) {
            $tmpDatabase = new SpreadsheetDatabase($source);
            return $this->loadFromDatabase($tmpDatabase, $options);
        }

        // Source is a database, load it.
        return $this->loadFromDatabase($source, $options);
    }

    /**
     * Determine if a string is a SQL dump.
     *
     * @param string $source The source string.
     * @return bool True if the string is a SQL dump, false otherwise.
     */
    protected function isSqlDump(string $source): bool
    {
        // Validate that it contains the essential elements of a dump.
        $patterns = [
            '/CREATE\s+TABLE\s+\w+\s*\(/i',  // Always defines a table.
            '/(INSERT\s+INTO|COPY\s+\w+\s+FROM)/i', // Always has data.
            '/\(/', // Parentheses in `CREATE TABLE` and `INSERT INTO`.
        ];

        // Validate that all patterns are present.
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $source)) {
                return false; // If one is missing, it is not a dump.
            }
        }

        // If all patterns are present, it is a dump.
        return true;
    }

    /**
     * Create the database schema.
     *
     * @return SchemaInterface The database schema.
     */
    abstract protected function createSchema(): SchemaInterface;

    /**
     * Load data from a dump according to the database type.
     *
     * @param string $source The source dump.
     * @param array $options The options for the load operation.
     * @return int The number of rows loaded.
     * @throws InvalidArgumentException If the source is not a valid dump.
     */
    abstract protected function loadFromDump(
        string $source,
        array $options = []
    ): int;

    /**
     * Load data from an array.
     *
     * @param array $source The source array.
     * @param array $options The options for the load operation.
     * @return int The number of rows loaded.
     */
    abstract protected function loadFromArray(
        array $source,
        array $options = []
    ): int;

    /**
     * Load data from a database.
     *
     * @param DatabaseInterface $source The source database.
     * @param array $options The options for the load operation.
     * @return int The number of rows loaded.
     */
    abstract protected function loadFromDatabase(
        DatabaseInterface $source,
        array $options = []
    ): int;
}
