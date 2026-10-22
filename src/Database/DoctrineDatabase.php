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

use Closure;
use Derafu\ETL\Database\Abstract\AbstractDatabase;
use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Database\Contract\TransactionalDatabaseInterface;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException as RuntimeException;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Driver as DoctrineDriver;
use Doctrine\DBAL\DriverManager as DoctrineDriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager as DoctrineAbstractSchemaManager;
use Doctrine\DBAL\Schema\ColumnDiff as DoctrineColumnDiff;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Schema\SchemaDiff as DoctrineSchemaDiff;
use Doctrine\DBAL\Schema\TableDiff as DoctrineTableDiff;

/**
 * Database implementation for Doctrine DBAL connections.
 *
 * This class is used to connect to a database using a Doctrine DBAL connection.
 *
 * @extends AbstractDatabase<DoctrineConnection>
 */
final class DoctrineDatabase extends AbstractDatabase implements TransactionalDatabaseInterface
{
    /**
     * The Doctrine schema manager.
     *
     * @var DoctrineAbstractSchemaManager<AbstractPlatform>
     */
    private DoctrineAbstractSchemaManager $schemaManager;

    /**
     * Constructor.
     *
     * @param DoctrineConnection|array<array-key, mixed>|string $doctrine The Doctrine DBAL connection.
     * @param array<array-key, mixed> $options The database options.
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
            $parameters = $this->validateConnectionParameters($doctrine);

            // Temporary DBAL boundary restriction: its drivers accept null
            // credentials, but DriverManager's PHPDoc requires strings when
            // these keys exist. Reject null here, including primary/replica
            // overrides, until Doctrine corrects that contract. Then remove
            // this boundary validation; the general validators allow null.
            if (array_key_exists('user', $parameters)) {
                $parameters['user'] = $this->connectionString($parameters['user'], 'user');
            }
            if (array_key_exists('password', $parameters)) {
                $parameters['password'] = $this->connectionString($parameters['password'], 'password');
            }
            if (isset($parameters['primary'])) {
                if (array_key_exists('user', $parameters['primary'])) {
                    $parameters['primary']['user'] = $this->connectionString($parameters['primary']['user'], 'primary.user');
                }
                if (array_key_exists('password', $parameters['primary'])) {
                    $parameters['primary']['password'] = $this->connectionString($parameters['primary']['password'], 'primary.password');
                }
            }
            if (isset($parameters['replica'])) {
                $replicas = [];
                foreach ($parameters['replica'] as $key => $replica) {
                    if (array_key_exists('user', $replica)) {
                        $replica['user'] = $this->connectionString($replica['user'], 'replica.user');
                    }
                    if (array_key_exists('password', $replica)) {
                        $replica['password'] = $this->connectionString($replica['password'], 'replica.password');
                    }
                    $replicas[$key] = $replica;
                }
                $parameters['replica'] = $replicas;
            }

            $doctrine = DoctrineDriverManager::getConnection($parameters);
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
        // IDEA under development, not an approved or final design: export table
        // structure and data as SQL for restoration into an empty database of
        // the same engine, optionally selecting one table, without DROP commands.
        // Account for SQL literals, foreign keys, indexes and sequence state;
        // verify export/restore round trips against real database engines.
        // Supported engines, dependencies of a selected table and exclusions
        // (such as views, triggers and routines) still need scope approval.
        // This would not replace native tools for complete production backups.

        throw new RuntimeException('Dump from Doctrine DBAL not implemented.');
    }

    /**
     * {@inheritDoc}
     */
    public function data(array $options = []): array
    {
        $table = $options['table'] ?? null;
        if ($table !== null && !is_string($table)) {
            throw new InvalidArgumentException([
                'Option "{option}" must be a string; {type} given.',
                'option' => 'table',
                'type' => get_debug_type($table),
            ]);
        }

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

        $schema = (new DoctrineSchemaSource($this->connection->getDatabasePlatform()))->extractSchema($doctrineSchema);

        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema($schema);

        // Populate the generated sheets directly. Loading this database through
        // SpreadsheetDatabase would request this conversion again recursively.
        foreach ($schema->getTables() as $table) {
            $name = $table->getName();
            $spreadsheet->createSheet($name, array_values($this->data(['table' => $name])), true);
        }

        return $spreadsheet;
    }

    /**
     * {@inheritDoc}
     */
    public function sync(
        DatabaseInterface $source,
        array $options = []
    ): self {
        $sqlStatements = $this->diffSchema($source->schema());
        $apply = function () use ($sqlStatements): self {
            foreach ($sqlStatements as $sql) {
                $this->connection->executeStatement($sql);
            }

            return $this;
        };

        try {
            // MySQL DDL implicitly commits. Do not create a transaction or
            // savepoint that the server will invalidate during synchronization.
            if ($sqlStatements !== [] && $this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
                return $apply();
            }

            return $this->connection->transactional($apply);
        } finally {
            $this->schema = null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function transactional(Closure $operation, ?DatabaseInterface $schemaSource = null): mixed
    {
        try {
            // A MySQL migration with DDL cannot be wrapped in one reversible
            // transaction. Keep it usable with the documented rollback limit.
            if ($schemaSource !== null
                && $this->connection->getDatabasePlatform() instanceof MySQLPlatform
                && $this->diffSchema($schemaSource->schema()) !== []
            ) {
                return $operation();
            }

            return $this->connection->transactional($operation);
        } finally {
            // Schema introspection performed during a rolled-back operation
            // must not leave a cached view of tables that no longer exist.
            $this->schema = null;
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function createSchema(): SchemaInterface
    {
        $doctrineSchema = $this->getSchemaManager()->introspectSchema();

        return (new DoctrineSchemaSource($this->connection->getDatabasePlatform()))->extractSchema($doctrineSchema);
    }

    /**
     * Get the Doctrine schema manager.
     *
     * @return DoctrineAbstractSchemaManager<AbstractPlatform> The Doctrine schema manager.
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
     * @return list<string> The SQL statements.
     */
    private function diffSchema(SchemaInterface|DoctrineSchema $sourceSchema): array
    {
        // If the source is a SchemaInterface, convert it to a DoctrineSchema.
        if ($sourceSchema instanceof SchemaInterface) {
            $sourceSchema = (new DoctrineSchemaTarget($this->connection->getDatabasePlatform()))->applySchema($sourceSchema);
        }

        // Get the target schema.
        $schemaManager = $this->getSchemaManager();
        $targetSchema = $schemaManager->introspectSchema();
        $this->preserveUnspecifiedTextOptions($sourceSchema, $targetSchema);

        // Compare the schemas and get the differences.
        $platform = $this->connection->getDatabasePlatform();
        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($targetSchema, $sourceSchema);
        $textChanges = $platform instanceof MySQLPlatform
            ? $this->mysqlTextChanges($sourceSchema, $targetSchema, $schemaDiff, $platform)
            : [];

        // If there are no differences, return an empty array.
        if ($schemaDiff->isEmpty() && $textChanges === []) {
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
        return array_merge($sqlStatements, $textChanges);
    }

    /**
     * An absent setting expresses no change to an existing destination asset.
     * Resolve it from that destination for comparison only, without modifying
     * the consumer's model or applying another engine's metadata. A partially
     * specified charset/collation pair is left to the backend to resolve.
     */
    private function preserveUnspecifiedTextOptions(DoctrineSchema $desired, DoctrineSchema $current): void
    {
        foreach ($desired->getTables() as $table) {
            if (!$current->hasTable($table->getName())) {
                continue;
            }
            $oldTable = $current->getTable($table->getName());
            if ($table->getOption('charset') === null && $table->getOption('collation') === null) {
                foreach (['charset', 'collation'] as $option) {
                    $value = $oldTable->getOption($option);
                    if ($value !== null) {
                        $table->addOption($option, $value);
                    }
                }
            }
            foreach ($table->getColumns() as $column) {
                if (!$oldTable->hasColumn($column->getName())
                    || $column->getCharset() !== null || $column->getCollation() !== null
                ) {
                    continue;
                }
                $old = $oldTable->getColumn($column->getName());
                if ($old->getCharset() !== null) {
                    $column->setPlatformOption('charset', $old->getCharset());
                }
                if ($old->getCollation() !== null) {
                    $column->setPlatformOption('collation', $old->getCollation());
                }
            }
        }
    }

    /**
     * DBAL normalizes inherited MySQL settings when comparing columns, but
     * does not report changes to table defaults. Preserve explicit settings
     * from the desired schema, including effective column settings that become
     * different when the two tables inherit different defaults.
     *
     * @return list<string>
     */
    private function mysqlTextChanges(
        DoctrineSchema $desired,
        DoctrineSchema $current,
        DoctrineSchemaDiff $diff,
        MySQLPlatform $platform
    ): array {
        $reported = [];
        foreach ($diff->getAlteredTables() as $change) {
            foreach ($change->getChangedColumns() as $column) {
                $reported[$change->getOldTable()->getName()][$column->getNewColumn()->getName()] = true;
            }
        }
        $statements = [];
        foreach ($desired->getTables() as $table) {
            if (!$current->hasTable($table->getName())) {
                continue;
            }
            $oldTable = $current->getTable($table->getName());
            $defaults = [];
            foreach (['charset' => 'DEFAULT CHARACTER SET', 'collation' => 'COLLATE'] as $option => $clause) {
                $value = $table->getOption($option);
                if (is_string($value) && $value !== '' && $value !== $oldTable->getOption($option)) {
                    $defaults[] = $clause . ' ' . $platform->quoteSingleIdentifier($value);
                }
            }
            if ($defaults !== []) {
                $statements[] = 'ALTER TABLE ' . $platform->quoteIdentifier($table->getName()) . ' ' . implode(' ', $defaults);
            }

            $columns = [];
            foreach ($table->getColumns() as $column) {
                $name = $column->getName();
                if (!$oldTable->hasColumn($name) || isset($reported[$table->getName()][$name])) {
                    continue;
                }
                $old = $oldTable->getColumn($name);
                if (($column->getCharset() !== null && $column->getCharset() !== $old->getCharset())
                    || ($column->getCollation() !== null && $column->getCollation() !== $old->getCollation())
                ) {
                    $columns[$name] = new DoctrineColumnDiff($old, $column);
                }
            }
            if ($columns !== []) {
                array_push($statements, ...$platform->getAlterTableSQL(new DoctrineTableDiff($oldTable, changedColumns: $columns)));
            }
        }

        return $statements;
    }

    /**
     * {@inheritDoc}
     *
     * Doctrine may return a string when an affected-row count exceeds the PHP
     * integer range. Keep the package's int contract and reject such counts.
     *
     * @throws RuntimeException If the count cannot be represented as a PHP int.
     */
    protected function loadFromDump(string $source, array $options = []): int
    {
        return $this->affectedRows($this->connection->executeStatement($source));
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromArray(array $source, array $options = []): int
    {
        // Validate and align all rows before opening a transaction.
        $validated = [];
        foreach ($source as $table => $rows) {
            if (!is_array($rows)) {
                throw new InvalidArgumentException([
                    'Rows for table "{table}" must be an array; {type} given.',
                    'table' => $table,
                    'type' => get_debug_type($rows),
                ]);
            }
            if ($rows !== []) {
                $validated[$table] = $this->validateRows($rows, (string) $table);
            }
        }

        // DBAL rolls back this transaction (or nested savepoint) on failure,
        // preserving any transaction already owned by the consumer.
        return $this->connection->transactional(function () use ($validated): int {
            $rowsLoaded = 0;
            foreach ($validated as $table => $rows) {
                [$query, $params] = $this->buildUpsertQuery((string) $table, $rows);
                $affected = $this->affectedRows($this->connection->executeStatement($query, $params));
                if (
                    ($affected > 0 && $rowsLoaded > PHP_INT_MAX - $affected)
                    || ($affected < 0 && $rowsLoaded < PHP_INT_MIN - $affected)
                ) {
                    throw new RuntimeException('Affected row count cannot be represented as a PHP integer.');
                }
                $rowsLoaded += $affected;
            }

            return $rowsLoaded;
        });
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromDatabase(
        DatabaseInterface $source,
        array $options = []
    ): int {
        return $this->connection->transactional(function () use ($source): int {
            // Metadata sheets are not business tables. Read errors also roll
            // back our transaction without closing the consumer's transaction.
            $data = [];
            foreach ($source->schema()->getTables() as $table) {
                $name = $table->getName();
                $data[$name] = $source->data(['table' => $name]);
            }

            return $this->loadFromArray($data);
        });
    }

    /**
     * Build an UPSERT query according to the database platform.
     *
     * @param string $table The table name.
     * @param non-empty-list<array<array-key, mixed>> $rows The validated rows to insert or update.
     * @return array{string, list<mixed>} The UPSERT query and the parameters.
     */
    private function buildUpsertQuery(
        string $table,
        array $rows
    ): array {
        // Get the columns of the table and initialize the parameters.
        $columns = array_map(
            static fn (int|string $column): string => (string) $column,
            array_keys($rows[0])
        );
        $params = [];

        // Flatten the aligned rows into positional parameters.
        foreach ($rows as $row) {
            $params = array_merge($params, array_values($row));
        }

        // Get the platform name, columns list, placeholders and values list.
        $platform = $this->connection->getDatabasePlatform();
        $quotedColumns = array_map($platform->quoteIdentifier(...), $columns);
        $columnsList = implode(', ', $quotedColumns);
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $valuesList = implode(', ', array_fill(0, count($rows), $placeholders));

        // If the platform is MySQL, build the UPSERT query.
        if ($platform instanceof MySQLPlatform) {
            $updates = implode(
                ', ',
                array_map(
                    static fn (string $col): string => sprintf(
                        '%s = VALUES(%s)',
                        $col,
                        $col
                    ),
                    $quotedColumns
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
            if ($table === '') {
                throw new InvalidArgumentException('PostgreSQL table names must not be empty.');
            }
            $primaryKey = $this->getSchemaManager()
                ->introspectTable($table)
                ->getPrimaryKeyConstraint()
            ;
            if ($primaryKey === null) {
                throw new InvalidArgumentException([
                    'Table "{table}" must have a primary key for PostgreSQL upserts.',
                    'table' => $table,
                ]);
            }
            $conflictColumns = implode(', ', array_map(
                static fn (UnqualifiedName $column): string => $column->toSQL($platform),
                $primaryKey->getColumnNames()
            ));
            $updates = implode(', ', array_map(static fn (string $col): string => "$col = EXCLUDED.$col", $quotedColumns));
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
        throw new RuntimeException([
            'Unsupported database platform: {platform}.',
            'platform' => $platform,
        ]);
    }

    /**
     * Normalize a driver's affected-row count without truncation.
     *
     * PHP_INT_MAX depends on the architecture (9223372036854775807 on 64-bit
     * PHP). Strings are accepted only when they represent an integer within
     * PHP_INT_MIN..PHP_INT_MAX, preserving the package's int return contract.
     *
     * @throws RuntimeException If the count cannot be represented as a PHP int.
     */
    private function affectedRows(int|string $count): int
    {
        if (is_int($count)) {
            return $count;
        }

        if (preg_match('/^[+-]?[0-9]+$/D', $count) === 1) {
            $negative = str_starts_with($count, '-');
            $digits = ltrim(ltrim($count, '+-'), '0');
            $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
            if (
                strlen($digits) < strlen($limit)
                || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0)
            ) {
                return (int) $count;
            }
        }

        throw new RuntimeException('Affected row count cannot be represented as a PHP integer.');
    }

    /**
     * @param non-empty-array<array-key, mixed> $rows
     * @return non-empty-list<array<array-key, mixed>>
     */
    private function validateRows(array $rows, string $table): array
    {
        $first = reset($rows);
        if (!is_array($first) || $first === []) {
            throw new InvalidArgumentException([
                'Every row in table "{table}" must be a non-empty array.',
                'table' => $table,
            ]);
        }
        $columns = array_keys($first);

        return array_values(array_map(function (mixed $row) use ($table, $columns): array {
            if (!is_array($row) || $row === []) {
                throw new InvalidArgumentException([
                    'Every row in table "{table}" must be a non-empty array.',
                    'table' => $table,
                ]);
            }
            if (array_diff($columns, array_keys($row)) !== [] || array_diff(array_keys($row), $columns) !== []) {
                throw new InvalidArgumentException([
                    'Every row in table "{table}" must have the same columns.',
                    'table' => $table,
                ]);
            }
            $ordered = [];
            foreach ($columns as $column) {
                $ordered[$column] = $row[$column];
            }

            return $ordered;
        }, $rows));
    }

    /**
     * Validate documented Doctrine parameters without removing extension options.
     *
     * @param array<array-key, mixed> $parameters
     * @return array{
     *     application_name?: string,
     *     charset?: string,
     *     connectstring?: string,
     *     dbname?: string,
     *     defaultTableOptions?: array<string, mixed>,
     *     driver?: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv',
     *     driverClass?: class-string<DoctrineDriver>,
     *     driverOptions?: array<array-key, mixed>,
     *     gssencmode?: string,
     *     host?: string,
     *     instancename?: string,
     *     memory?: bool,
     *     password?: string|null,
     *     path?: string,
     *     persistent?: bool,
     *     pooled?: bool,
     *     port?: int,
     *     serverVersion?: string,
     *     service?: bool,
     *     servicename?: string,
     *     sessionMode?: int,
     *     ssl_ca?: string,
     *     ssl_capath?: string,
     *     ssl_cert?: string,
     *     ssl_cipher?: string,
     *     ssl_key?: string,
     *     sslcert?: string,
     *     sslcrl?: string,
     *     sslkey?: string,
     *     sslmode?: string,
     *     sslrootcert?: string,
     *     unix_socket?: string,
     *     user?: string|null,
     *     wrapperClass?: class-string<DoctrineConnection>,
     *     sid?: string,
     *     keepReplica?: bool,
     *     primary?: array{
     *         application_name?: string,
     *         charset?: string,
     *         connectstring?: string,
     *         dbname?: string,
     *         defaultTableOptions?: array<string, mixed>,
     *         driver?: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv',
     *         driverClass?: class-string<DoctrineDriver>,
     *         driverOptions?: array<array-key, mixed>,
     *         gssencmode?: string,
     *         host?: string,
     *         instancename?: string,
     *         memory?: bool,
     *         password?: string|null,
     *         path?: string,
     *         persistent?: bool,
     *         pooled?: bool,
     *         port?: int,
     *         serverVersion?: string,
     *         service?: bool,
     *         servicename?: string,
     *         sessionMode?: int,
     *         ssl_ca?: string,
     *         ssl_capath?: string,
     *         ssl_cert?: string,
     *         ssl_cipher?: string,
     *         ssl_key?: string,
     *         sslcert?: string,
     *         sslcrl?: string,
     *         sslkey?: string,
     *         sslmode?: string,
     *         sslrootcert?: string,
     *         unix_socket?: string,
     *         user?: string|null,
     *         wrapperClass?: class-string<DoctrineConnection>,
     *         ...
     *     },
     *     replica?: array<array{
     *         application_name?: string,
     *         charset?: string,
     *         connectstring?: string,
     *         dbname?: string,
     *         defaultTableOptions?: array<string, mixed>,
     *         driver?: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv',
     *         driverClass?: class-string<DoctrineDriver>,
     *         driverOptions?: array<array-key, mixed>,
     *         gssencmode?: string,
     *         host?: string,
     *         instancename?: string,
     *         memory?: bool,
     *         password?: string|null,
     *         path?: string,
     *         persistent?: bool,
     *         pooled?: bool,
     *         port?: int,
     *         serverVersion?: string,
     *         service?: bool,
     *         servicename?: string,
     *         sessionMode?: int,
     *         ssl_ca?: string,
     *         ssl_capath?: string,
     *         ssl_cert?: string,
     *         ssl_cipher?: string,
     *         ssl_key?: string,
     *         sslcert?: string,
     *         sslcrl?: string,
     *         sslkey?: string,
     *         sslmode?: string,
     *         sslrootcert?: string,
     *         unix_socket?: string,
     *         user?: string|null,
     *         wrapperClass?: class-string<DoctrineConnection>,
     *         ...
     *     }>,
     *     ...
     * }
     */
    private function validateConnectionParameters(array $parameters): array
    {
        $parameters = $this->validateConnectionOverrides($parameters);
        if (array_key_exists('sid', $parameters)) {
            $parameters['sid'] = $this->connectionString($parameters['sid'], 'sid');
        }
        if (array_key_exists('keepReplica', $parameters)) {
            $parameters['keepReplica'] = $this->connectionBool($parameters['keepReplica'], 'keepReplica');
        }
        if (array_key_exists('primary', $parameters)) {
            $parameters['primary'] = $this->validateConnectionOverrides($this->connectionArray($parameters['primary'], 'primary'));
        }
        if (array_key_exists('replica', $parameters)) {
            $replicas = $this->connectionArray($parameters['replica'], 'replica');
            $parameters['replica'] = array_map(
                fn (mixed $replica): array => $this->validateConnectionOverrides($this->connectionArray($replica, 'replica')),
                $replicas
            );
        }

        return $parameters;
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return array{
     *     application_name?: string,
     *     charset?: string,
     *     connectstring?: string,
     *     dbname?: string,
     *     defaultTableOptions?: array<string, mixed>,
     *     driver?: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv',
     *     driverClass?: class-string<DoctrineDriver>,
     *     driverOptions?: array<array-key, mixed>,
     *     gssencmode?: string,
     *     host?: string,
     *     instancename?: string,
     *     memory?: bool,
     *     password?: string|null,
     *     path?: string,
     *     persistent?: bool,
     *     pooled?: bool,
     *     port?: int,
     *     serverVersion?: string,
     *     service?: bool,
     *     servicename?: string,
     *     sessionMode?: int,
     *     ssl_ca?: string,
     *     ssl_capath?: string,
     *     ssl_cert?: string,
     *     ssl_cipher?: string,
     *     ssl_key?: string,
     *     sslcert?: string,
     *     sslcrl?: string,
     *     sslkey?: string,
     *     sslmode?: string,
     *     sslrootcert?: string,
     *     unix_socket?: string,
     *     user?: string|null,
     *     wrapperClass?: class-string<DoctrineConnection>,
     *     ...
     * }
     */
    private function validateConnectionOverrides(array $parameters): array
    {
        if (array_key_exists('application_name', $parameters)) {
            $parameters['application_name'] = $this->connectionString($parameters['application_name'], 'application_name');
        }
        if (array_key_exists('charset', $parameters)) {
            $parameters['charset'] = $this->connectionString($parameters['charset'], 'charset');
        }
        if (array_key_exists('connectstring', $parameters)) {
            $parameters['connectstring'] = $this->connectionString($parameters['connectstring'], 'connectstring');
        }
        if (array_key_exists('dbname', $parameters)) {
            $parameters['dbname'] = $this->connectionString($parameters['dbname'], 'dbname');
        }
        if (array_key_exists('gssencmode', $parameters)) {
            $parameters['gssencmode'] = $this->connectionString($parameters['gssencmode'], 'gssencmode');
        }
        if (array_key_exists('host', $parameters)) {
            $parameters['host'] = $this->connectionString($parameters['host'], 'host');
        }
        if (array_key_exists('instancename', $parameters)) {
            $parameters['instancename'] = $this->connectionString($parameters['instancename'], 'instancename');
        }
        if (isset($parameters['password'])) {
            $parameters['password'] = $this->connectionString($parameters['password'], 'password');
        }
        if (array_key_exists('path', $parameters)) {
            $parameters['path'] = $this->connectionString($parameters['path'], 'path');
        }
        if (array_key_exists('serverVersion', $parameters)) {
            $parameters['serverVersion'] = $this->connectionString($parameters['serverVersion'], 'serverVersion');
        }
        if (array_key_exists('servicename', $parameters)) {
            $parameters['servicename'] = $this->connectionString($parameters['servicename'], 'servicename');
        }
        if (array_key_exists('ssl_ca', $parameters)) {
            $parameters['ssl_ca'] = $this->connectionString($parameters['ssl_ca'], 'ssl_ca');
        }
        if (array_key_exists('ssl_capath', $parameters)) {
            $parameters['ssl_capath'] = $this->connectionString($parameters['ssl_capath'], 'ssl_capath');
        }
        if (array_key_exists('ssl_cert', $parameters)) {
            $parameters['ssl_cert'] = $this->connectionString($parameters['ssl_cert'], 'ssl_cert');
        }
        if (array_key_exists('ssl_cipher', $parameters)) {
            $parameters['ssl_cipher'] = $this->connectionString($parameters['ssl_cipher'], 'ssl_cipher');
        }
        if (array_key_exists('ssl_key', $parameters)) {
            $parameters['ssl_key'] = $this->connectionString($parameters['ssl_key'], 'ssl_key');
        }
        if (array_key_exists('sslcert', $parameters)) {
            $parameters['sslcert'] = $this->connectionString($parameters['sslcert'], 'sslcert');
        }
        if (array_key_exists('sslcrl', $parameters)) {
            $parameters['sslcrl'] = $this->connectionString($parameters['sslcrl'], 'sslcrl');
        }
        if (array_key_exists('sslkey', $parameters)) {
            $parameters['sslkey'] = $this->connectionString($parameters['sslkey'], 'sslkey');
        }
        if (array_key_exists('sslmode', $parameters)) {
            $parameters['sslmode'] = $this->connectionString($parameters['sslmode'], 'sslmode');
        }
        if (array_key_exists('sslrootcert', $parameters)) {
            $parameters['sslrootcert'] = $this->connectionString($parameters['sslrootcert'], 'sslrootcert');
        }
        if (array_key_exists('unix_socket', $parameters)) {
            $parameters['unix_socket'] = $this->connectionString($parameters['unix_socket'], 'unix_socket');
        }
        if (isset($parameters['user'])) {
            $parameters['user'] = $this->connectionString($parameters['user'], 'user');
        }
        if (array_key_exists('memory', $parameters)) {
            $parameters['memory'] = $this->connectionBool($parameters['memory'], 'memory');
        }
        if (array_key_exists('persistent', $parameters)) {
            $parameters['persistent'] = $this->connectionBool($parameters['persistent'], 'persistent');
        }
        if (array_key_exists('pooled', $parameters)) {
            $parameters['pooled'] = $this->connectionBool($parameters['pooled'], 'pooled');
        }
        if (array_key_exists('service', $parameters)) {
            $parameters['service'] = $this->connectionBool($parameters['service'], 'service');
        }
        if (array_key_exists('port', $parameters)) {
            $parameters['port'] = $this->connectionInt($parameters['port'], 'port');
        }
        if (array_key_exists('sessionMode', $parameters)) {
            $parameters['sessionMode'] = $this->connectionInt($parameters['sessionMode'], 'sessionMode');
        }
        if (array_key_exists('driverOptions', $parameters)) {
            $parameters['driverOptions'] = $this->connectionArray($parameters['driverOptions'], 'driverOptions');
        }
        if (array_key_exists('defaultTableOptions', $parameters)) {
            $parameters['defaultTableOptions'] = $this->connectionArray($parameters['defaultTableOptions'], 'defaultTableOptions');
            $options = [];
            foreach ($parameters['defaultTableOptions'] as $key => $value) {
                if (!is_string($key)) {
                    throw new InvalidArgumentException('Doctrine defaultTableOptions must have string keys.');
                }
                $options[$key] = $value;
            }
            $parameters['defaultTableOptions'] = $options;
        }
        if (array_key_exists('driver', $parameters)) {
            $driver = $this->connectionString($parameters['driver'], 'driver');
            if (!in_array($driver, DoctrineDriverManager::getAvailableDrivers(), true)) {
                throw new InvalidArgumentException('Unknown Doctrine driver.');
            }
            $parameters['driver'] = $driver;
        }
        if (array_key_exists('driverClass', $parameters)) {
            $class = $parameters['driverClass'];
            if (!is_string($class) || !is_a($class, DoctrineDriver::class, true)) {
                throw new InvalidArgumentException('Doctrine driverClass must implement Doctrine DBAL Driver.');
            }
            $parameters['driverClass'] = $class;
        }
        if (array_key_exists('wrapperClass', $parameters)) {
            $class = $parameters['wrapperClass'];
            if (!is_string($class) || !is_a($class, DoctrineConnection::class, true)) {
                throw new InvalidArgumentException('Doctrine wrapperClass must extend Doctrine DBAL Connection.');
            }
            $parameters['wrapperClass'] = $class;
        }

        return $parameters;
    }

    private function connectionString(mixed $value, string $option): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException([
                'Doctrine option "{option}" must be a string; {type} given.',
                'option' => $option,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    private function connectionBool(mixed $value, string $option): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException([
                'Doctrine option "{option}" must be a boolean; {type} given.',
                'option' => $option,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    private function connectionInt(mixed $value, string $option): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException([
                'Doctrine option "{option}" must be an integer; {type} given.',
                'option' => $option,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function connectionArray(mixed $value, string $option): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException([
                'Doctrine option "{option}" must be an array; {type} given.',
                'option' => $option,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }
}
