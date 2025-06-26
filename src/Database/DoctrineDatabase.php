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
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\DriverManager as DoctrineDriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager as DoctrineAbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator as DoctrineComparator;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use RuntimeException;

/**
 * Database implementation for Doctrine DBAL connections.
 *
 * This class is used to connect to a database using a Doctrine DBAL connection.
 */
final class DoctrineDatabase extends AbstractDatabase implements DatabaseInterface
{
    /**
     * The Doctrine schema manager.
     *
     * @var DoctrineAbstractSchemaManager
     */
    private DoctrineAbstractSchemaManager $schemaManager;

    /**
     * Constructor.
     *
     * @param DoctrineConnection $doctrine The Doctrine DBAL connection.
     * @param array $options The database options.
     */
    public function __construct(
        DoctrineConnection|array|string $doctrine,
        array $options = []
    ) {
        if (is_string($doctrine)) {
            $doctrine = DoctrineDriverManager::getConnection([
                'path' => $doctrine,
                'driver' => 'pdo_sqlite',
            ]);
        }

        if (is_array($doctrine)) {
            $doctrine = DoctrineDriverManager::getConnection($doctrine);
        }

        parent::__construct($doctrine, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function dump(array $options = []): string
    {
        $table = $options['table'] ?? null;

        // TODO: Implement dump() method.
        throw new RuntimeException('Dump from Doctrine DBAL not implemented.');
    }

    /**
     * {@inheritDoc}
     */
    public function data(array $options = []): array
    {
        $table = $options['table'] ?? null;

        $tables = $table
            ? [$table]
            : $this->getSchemaManager()->listTableNames()
        ;

        $data = [];
        foreach ($tables as $name) {
            $query = sprintf(
                'SELECT * FROM %s',
                $this->connection->quoteIdentifier($name)
            );
            $data[$name] = $this->connection->fetchAllAssociative($query);
        }

        return $table ? $data[$table] : $data;
    }

    /**
     * {@inheritDoc}
     */
    public function spreadsheet(array $options = []): SpreadsheetInterface
    {
        $doctrineSchema = $this->getSchemaManager()->introspectSchema();

        $schema = (new DoctrineSchemaSource())->extractSchema($doctrineSchema);

        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema($schema);

        $database = new SpreadsheetDatabase($spreadsheet);

        $database->loadFromDatabase($this);

        return $database->spreadsheet();
    }

    /**
     * {@inheritDoc}
     */
    public function sync(
        DatabaseInterface $source,
        array $options = []
    ): self {
        // Start a transaction.
        $this->connection->beginTransaction();

        // Get the SQL statements of differences and apply them.
        $sqlStatements = $this->diffSchema($source->schema());
        foreach ($sqlStatements as $sql) {
            $this->connection->executeStatement($sql);
        }

        // Commit the transaction.
        $this->connection->commit();

        // Return the database.
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    protected function createSchema(): SchemaInterface
    {
        $doctrineSchema = $this->getSchemaManager()->introspectSchema();

        return (new DoctrineSchemaSource())->extractSchema($doctrineSchema);
    }

    /**
     * Get the Doctrine schema manager.
     *
     * @return DoctrineAbstractSchemaManager The Doctrine schema manager.
     */
    private function getSchemaManager(): DoctrineAbstractSchemaManager
    {
        if (!isset($this->schemaManager)) {
            $this->schemaManager = $this->connection->createSchemaManager();
        }

        return $this->schemaManager;
    }

    /**
     * Get the SQL statements to create the structure.
     *
     * @param SchemaInterface|DoctrineSchema $sourceSchema The source schema.
     * @return array The SQL statements.
     */
    private function diffSchema(SchemaInterface|DoctrineSchema $sourceSchema): array
    {
        // If the source is a SchemaInterface, convert it to a DoctrineSchema.
        if ($sourceSchema instanceof SchemaInterface) {
            $sourceSchema = (new DoctrineSchemaTarget())->applySchema($sourceSchema);
        }

        // Get the target schema.
        $schemaManager = $this->getSchemaManager();
        $targetSchema = $schemaManager->introspectSchema();

        // Compare the schemas and get the differences.
        $platform = $this->connection->getDatabasePlatform();
        $comparator = new DoctrineComparator($platform);
        $schemaDiff = $comparator->compareSchemas($targetSchema, $sourceSchema);

        // If there are no differences, return an empty array.
        if ($schemaDiff->isEmpty()) {
            return [];
        }

        // Get the SQL statements to create the structure.
        $sqlStatements = [];

        // Get the SQL statements to create the structure.
        foreach ($schemaDiff->getCreatedTables() as $table) {
            $sqlStatements = array_merge(
                $sqlStatements,
                $platform->getCreateTableSQL($table)
            );
        }

        // Get the SQL statements to alter the structure.
        foreach ($schemaDiff->getAlteredTables() as $tableDiff) {
            $sqlStatements = array_merge(
                $sqlStatements,
                $platform->getAlterTableSQL($tableDiff)
            );
        }

        // Get the SQL statements to drop the structure.
        foreach ($schemaDiff->getDroppedTables() as $table) {
            $sqlStatements[] = sprintf("DROP TABLE %s;", $table->getName());
        }

        // Return the SQL statements.
        return $sqlStatements;
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromDump(string $source, array $options = []): int
    {
        return $this->connection->executeStatement($source);
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromArray(array $source, array $options = []): int
    {
        // Start a transaction.
        $this->connection->beginTransaction();

        // Insert or update the data.
        $rowsLoaded = 0;
        foreach ($source as $table => $rows) {
            // If there is no data, skip this table.
            if (empty($rows)) {
                continue;
            }

            // Create the query to insert or update the data.
            [$query, $params] = $this->buildUpsertQuery($table, $rows);

            // Execute the query.
            $rowsLoaded += $this->connection->executeStatement($query, $params);
        }

        // Commit the transaction.
        $this->connection->commit();

        // Return the number of rows loaded.
        return $rowsLoaded;
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromDatabase(
        DatabaseInterface $source,
        array $options = []
    ): int {
        // Start a transaction.
        $this->connection->beginTransaction();

        // Insert or update the data.
        $rowsLoaded = $this->loadFromArray($source->data());

        // Commit the transaction.
        $this->connection->commit();

        // Return the number of rows loaded.
        return $rowsLoaded;
    }

    /**
     * Build an UPSERT query according to the database platform.
     *
     * @param string $table The table name.
     * @param array $rows The rows to insert or update.
     * @return array The UPSERT query and the parameters.
     */
    private function buildUpsertQuery(
        string $table,
        array $rows
    ): array {
        // Get the columns of the table, create the placeholders and empty
        // values and params arrays.
        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $values = [];
        $params = [];

        // For each row, merge the params and add the placeholders to the values
        // array.
        foreach ($rows as $row) {
            $params = array_merge($params, array_values($row));
            $values[] = $placeholders;
        }

        // Get the platform name, columns list, placeholders and values list.
        $platform = $this->connection->getDatabasePlatform();
        $columnsList = implode(', ', $columns);
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $valuesList = implode(', ', array_fill(0, count($rows), $placeholders));

        // If the platform is MySQL, build the UPSERT query.
        if ($platform instanceof MySQLPlatform) {
            $updates = implode(
                ', ',
                array_map(
                    fn ($col) => sprintf(
                        '%s = VALUES(%s)',
                        $platform->quoteIdentifier($col),
                        $col
                    ),
                    $columns
                )
            );
            return [
                sprintf(
                    'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
                    $platform->quoteIdentifier($table),
                    $columnsList,
                    $valuesList,
                    $updates
                ),
                $params,
            ];
        }

        // If the platform is PostgreSQL, build the UPSERT query.
        if ($platform instanceof PostgreSQLPlatform) {
            $primaryKey = $this->connection
                ->getSchemaManager()
                ->listTableDetails($table)
                ->getPrimaryKeyColumns()
            ;
            $conflictColumns = implode(', ', $primaryKey);
            $updates = implode(', ', array_map(fn ($col) => "$col = EXCLUDED.$col", $columns));
            return [
                sprintf(
                    'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
                    $platform->quoteIdentifier($table),
                    $columnsList,
                    $valuesList,
                    $conflictColumns,
                    $updates
                ),
                $params,
            ];
        }

        // If the platform is SQLite, build the UPSERT query.
        if ($platform instanceof SQLitePlatform) {
            return [
                sprintf(
                    'INSERT OR REPLACE INTO %s (%s) VALUES %s',
                    $platform->quoteIdentifier($table),
                    $columnsList,
                    $valuesList
                ),
                $params,
            ];
        }

        // If the platform is not supported, throw an exception.
        throw new RuntimeException(sprintf(
            'Unsupported database platform: %s.',
            $platform
        ));
    }
}
