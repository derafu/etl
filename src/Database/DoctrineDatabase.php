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
use Derafu\Spreadsheet\Contract\SpreadsheetFactoryInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Spreadsheet\SpreadsheetFactory;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException as RuntimeException;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Value\Exception\ValueException;
use Derafu\Value\ValueType;
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
     * @param SpreadsheetFactoryInterface $spreadsheetFactory Factory for spreadsheet exports.
     */
    public function __construct(
        DoctrineConnection|array|string $doctrine,
        array $options = [],
        private readonly SpreadsheetFactoryInterface $spreadsheetFactory = new SpreadsheetFactory()
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
                $parameters['user'] = $this->connectionValue($parameters['user'], 'user', ValueType::string());
            }
            if (array_key_exists('password', $parameters)) {
                $parameters['password'] = $this->connectionValue($parameters['password'], 'password', ValueType::string());
            }
            if (isset($parameters['primary'])) {
                if (array_key_exists('user', $parameters['primary'])) {
                    $parameters['primary']['user'] = $this->connectionValue($parameters['primary']['user'], 'primary.user', ValueType::string());
                }
                if (array_key_exists('password', $parameters['primary'])) {
                    $parameters['primary']['password'] = $this->connectionValue($parameters['primary']['password'], 'primary.password', ValueType::string());
                }
            }
            if (isset($parameters['replica'])) {
                $replicas = $parameters['replica'];
                if ($replicas === []) {
                    throw new InvalidArgumentException([
                        'Doctrine option "{option}" must contain at least one connection.',
                        'option' => 'replica',
                    ]);
                }
                foreach ($replicas as $key => $replica) {
                    if (array_key_exists('user', $replica)) {
                        $replica['user'] = $this->connectionValue($replica['user'], 'replica.user', ValueType::string());
                    }
                    if (array_key_exists('password', $replica)) {
                        $replica['password'] = $this->connectionValue($replica['password'], 'replica.password', ValueType::string());
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

        $spreadsheet = (new SpreadsheetSchemaTarget(spreadsheetFactory: $this->spreadsheetFactory))->applySchema($schema);

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
            try {
                return ValueType::toInt()->parse($count);
            } catch (ValueException $exception) {
                throw new RuntimeException('Affected row count cannot be represented as a PHP integer.', previous: $exception);
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
            $parameters['sid'] = $this->connectionValue($parameters['sid'], 'sid', ValueType::string());
        }
        if (array_key_exists('keepReplica', $parameters)) {
            $parameters['keepReplica'] = $this->connectionValue($parameters['keepReplica'], 'keepReplica', ValueType::bool());
        }
        if (array_key_exists('primary', $parameters)) {
            $parameters['primary'] = $this->validateConnectionOverrides($this->connectionValue($parameters['primary'], 'primary', ValueType::array()));
        }
        if (array_key_exists('replica', $parameters)) {
            $replicas = $this->connectionValue($parameters['replica'], 'replica', ValueType::array());
            $parameters['replica'] = array_map(
                fn (mixed $replica): array => $this->validateConnectionOverrides($this->connectionValue($replica, 'replica', ValueType::array())),
                $replicas
            );
        }

        /**
         * Runtime checks establish the types of the known options while driver
         * and wrapper extension options retain their original values and order.
         * PHPStan loses optional-field types when these open arrays are updated
         * conditionally. Copying, merging or rebuilding an open array does not
         * recover that shape; filtering to known keys discards supported options.
         * This assertion is required for this contract with the current analyzer.
         * Keep it synchronized with the checks above and the return contract:
         * the annotation performs no validation and can hide missing checks.
         *
         * @var array{
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
         * } $parameters
         */
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
            $parameters['application_name'] = $this->connectionValue($parameters['application_name'], 'application_name', ValueType::string());
        }
        if (array_key_exists('charset', $parameters)) {
            $parameters['charset'] = $this->connectionValue($parameters['charset'], 'charset', ValueType::string());
        }
        if (array_key_exists('connectstring', $parameters)) {
            $parameters['connectstring'] = $this->connectionValue($parameters['connectstring'], 'connectstring', ValueType::string());
        }
        if (array_key_exists('dbname', $parameters)) {
            $parameters['dbname'] = $this->connectionValue($parameters['dbname'], 'dbname', ValueType::string());
        }
        if (array_key_exists('gssencmode', $parameters)) {
            $parameters['gssencmode'] = $this->connectionValue($parameters['gssencmode'], 'gssencmode', ValueType::string());
        }
        if (array_key_exists('host', $parameters)) {
            $parameters['host'] = $this->connectionValue($parameters['host'], 'host', ValueType::string());
        }
        if (array_key_exists('instancename', $parameters)) {
            $parameters['instancename'] = $this->connectionValue($parameters['instancename'], 'instancename', ValueType::string());
        }
        if (isset($parameters['password'])) {
            $parameters['password'] = $this->connectionValue($parameters['password'], 'password', ValueType::string());
        }
        if (array_key_exists('path', $parameters)) {
            $parameters['path'] = $this->connectionValue($parameters['path'], 'path', ValueType::string());
        }
        if (array_key_exists('serverVersion', $parameters)) {
            $parameters['serverVersion'] = $this->connectionValue($parameters['serverVersion'], 'serverVersion', ValueType::string());
        }
        if (array_key_exists('servicename', $parameters)) {
            $parameters['servicename'] = $this->connectionValue($parameters['servicename'], 'servicename', ValueType::string());
        }
        if (array_key_exists('ssl_ca', $parameters)) {
            $parameters['ssl_ca'] = $this->connectionValue($parameters['ssl_ca'], 'ssl_ca', ValueType::string());
        }
        if (array_key_exists('ssl_capath', $parameters)) {
            $parameters['ssl_capath'] = $this->connectionValue($parameters['ssl_capath'], 'ssl_capath', ValueType::string());
        }
        if (array_key_exists('ssl_cert', $parameters)) {
            $parameters['ssl_cert'] = $this->connectionValue($parameters['ssl_cert'], 'ssl_cert', ValueType::string());
        }
        if (array_key_exists('ssl_cipher', $parameters)) {
            $parameters['ssl_cipher'] = $this->connectionValue($parameters['ssl_cipher'], 'ssl_cipher', ValueType::string());
        }
        if (array_key_exists('ssl_key', $parameters)) {
            $parameters['ssl_key'] = $this->connectionValue($parameters['ssl_key'], 'ssl_key', ValueType::string());
        }
        if (array_key_exists('sslcert', $parameters)) {
            $parameters['sslcert'] = $this->connectionValue($parameters['sslcert'], 'sslcert', ValueType::string());
        }
        if (array_key_exists('sslcrl', $parameters)) {
            $parameters['sslcrl'] = $this->connectionValue($parameters['sslcrl'], 'sslcrl', ValueType::string());
        }
        if (array_key_exists('sslkey', $parameters)) {
            $parameters['sslkey'] = $this->connectionValue($parameters['sslkey'], 'sslkey', ValueType::string());
        }
        if (array_key_exists('sslmode', $parameters)) {
            $parameters['sslmode'] = $this->connectionValue($parameters['sslmode'], 'sslmode', ValueType::string());
        }
        if (array_key_exists('sslrootcert', $parameters)) {
            $parameters['sslrootcert'] = $this->connectionValue($parameters['sslrootcert'], 'sslrootcert', ValueType::string());
        }
        if (array_key_exists('unix_socket', $parameters)) {
            $parameters['unix_socket'] = $this->connectionValue($parameters['unix_socket'], 'unix_socket', ValueType::string());
        }
        if (isset($parameters['user'])) {
            $parameters['user'] = $this->connectionValue($parameters['user'], 'user', ValueType::string());
        }
        if (array_key_exists('memory', $parameters)) {
            $parameters['memory'] = $this->connectionValue($parameters['memory'], 'memory', ValueType::bool());
        }
        if (array_key_exists('persistent', $parameters)) {
            $parameters['persistent'] = $this->connectionValue($parameters['persistent'], 'persistent', ValueType::bool());
        }
        if (array_key_exists('pooled', $parameters)) {
            $parameters['pooled'] = $this->connectionValue($parameters['pooled'], 'pooled', ValueType::bool());
        }
        if (array_key_exists('service', $parameters)) {
            $parameters['service'] = $this->connectionValue($parameters['service'], 'service', ValueType::bool());
        }
        if (array_key_exists('port', $parameters)) {
            $parameters['port'] = $this->connectionValue($parameters['port'], 'port', ValueType::int());
        }
        if (array_key_exists('sessionMode', $parameters)) {
            $parameters['sessionMode'] = $this->connectionValue($parameters['sessionMode'], 'sessionMode', ValueType::int());
        }
        if (array_key_exists('driverOptions', $parameters)) {
            $parameters['driverOptions'] = $this->connectionValue($parameters['driverOptions'], 'driverOptions', ValueType::array());
        }
        if (array_key_exists('defaultTableOptions', $parameters)) {
            $options = $this->connectionValue($parameters['defaultTableOptions'], 'defaultTableOptions', ValueType::array());
            try {
                $parameters['defaultTableOptions'] = ValueType::map()->parse($options, path: 'defaultTableOptions');
            } catch (ValueException $exception) {
                throw new InvalidArgumentException('Doctrine defaultTableOptions must have string keys.', previous: $exception);
            }
        }
        if (array_key_exists('driver', $parameters)) {
            $driver = $this->connectionValue($parameters['driver'], 'driver', ValueType::string());
            if (!in_array($driver, DoctrineDriverManager::getAvailableDrivers(), true)) {
                throw new InvalidArgumentException('Unknown Doctrine driver.');
            }
            $parameters['driver'] = $driver;
        }
        if (array_key_exists('driverClass', $parameters)) {
            try {
                $parameters['driverClass'] = ValueType::classString(DoctrineDriver::class)->parse($parameters['driverClass'], path: 'driverClass');
            } catch (ValueException $exception) {
                throw new InvalidArgumentException('Doctrine driverClass must implement Doctrine DBAL Driver.', previous: $exception);
            }
        }
        if (array_key_exists('wrapperClass', $parameters)) {
            try {
                $parameters['wrapperClass'] = ValueType::classString(DoctrineConnection::class)->parse($parameters['wrapperClass'], path: 'wrapperClass');
            } catch (ValueException $exception) {
                throw new InvalidArgumentException('Doctrine wrapperClass must extend Doctrine DBAL Connection.', previous: $exception);
            }
        }

        /**
         * Runtime checks establish the types of the known options while driver
         * and wrapper extension options retain their original values and order.
         * PHPStan loses optional-field types when these open arrays are updated
         * conditionally. Copying, merging or rebuilding an open array does not
         * recover that shape; filtering to known keys discards supported options.
         * This assertion is required for this contract with the current analyzer.
         * Keep it synchronized with the checks above and the return contract:
         * the annotation performs no validation and can hide missing checks.
         *
         * @var array{
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
         * } $parameters
         */
        return $parameters;
    }

    /**
     * Validate a value while retaining the package's contextual exception.
     *
     * @template T
     * @param mixed $value
     * @param string $field
     * @param ValueType<T> $type
     * @return T
     */
    private function connectionValue(mixed $value, string $field, ValueType $type): mixed
    {
        try {
            return $type->parse($value, path: $field);
        } catch (ValueException $exception) {
            throw match ($type->name) {
                'string' => new InvalidArgumentException([
                    'Doctrine option "{option}" must be a string; {type} given.',
                    'option' => $field,
                    'type' => get_debug_type($value),
                ], previous: $exception),
                'bool' => new InvalidArgumentException([
                    'Doctrine option "{option}" must be a boolean; {type} given.',
                    'option' => $field,
                    'type' => get_debug_type($value),
                ], previous: $exception),
                'int' => new InvalidArgumentException([
                    'Doctrine option "{option}" must be an integer; {type} given.',
                    'option' => $field,
                    'type' => get_debug_type($value),
                ], previous: $exception),
                default => new InvalidArgumentException([
                    'Doctrine option "{option}" must be an array; {type} given.',
                    'option' => $field,
                    'type' => get_debug_type($value),
                ], previous: $exception),
            };
        }
    }
}
