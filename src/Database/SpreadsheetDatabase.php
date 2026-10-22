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
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\SchemaSourceInterface;
use Derafu\ETL\Schema\Contract\TableInterface;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\Spreadsheet\Contract\SpreadsheetDumperInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetLoaderInterface;
use Derafu\Spreadsheet\Sheet;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetLoader;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Throwable;

/**
 * Database implementation for spreadsheets.
 *
 * @extends AbstractDatabase<SpreadsheetInterface>
 */
final class SpreadsheetDatabase extends AbstractDatabase implements TransactionalDatabaseInterface
{
    private SchemaSourceInterface $schemaSource;

    private SpreadsheetLoaderInterface $loader;

    private SpreadsheetDumperInterface $dumper;

    /**
     * Constructor.
     *
     * @param SpreadsheetInterface $spreadsheet The spreadsheet.
     * @param array<array-key, mixed> $options The options for the database (not only for spreadsheet).
     */
    public function __construct(
        SpreadsheetInterface $spreadsheet,
        array $options = [],
        ?SchemaSourceInterface $schemaSource = null,
        ?SpreadsheetLoaderInterface $loader = null,
        ?SpreadsheetDumperInterface $dumper = null
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
        $format = $this->requireString($options['format'] ?? $this->options['format'] ?? 'xlsx', 'format');

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
     *
     * Without a table selection, return only tables declared in the schema.
     * The complete document, including metadata sheets, remains available
     * through spreadsheet(); schema() exposes its structured definition.
     */
    public function data(array $options = []): array
    {
        $table = isset($options['table']) ? $this->requireString($options['table'], 'table') : null;

        if ($table === null) {
            $tables = array_intersect_key(
                $this->connection->toArray(),
                $this->schema()->getTables()
            );
        } else {
            $sheet = $this->connection->getSheet($table);
            if ($sheet === null) {
                throw new InvalidArgumentException([
                    'Table "{table}" not found in spreadsheet.',
                    'table' => $table,
                ]);
            }
            $tables = [$table => $sheet->toArray()];
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
     *
     * Stage the complete document, retaining its sheets and active selection.
     * This does not write files; save() remains a separate operation.
     */
    public function transactional(Closure $operation, ?DatabaseInterface $schemaSource = null): mixed
    {
        $original = $this->connection;
        $staged = clone $original;
        foreach ($original->getSheets() as $sheet) {
            $staged->addSheet(clone $sheet);
        }
        $this->connection = $staged;
        $this->schema = null;

        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->connection = $original;
            throw $exception;
        } finally {
            $this->schema = null;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Apply source table definitions without loading their rows. Reuse the
     * loading policies for existing tables, incompatible definitions and drop
     * options, preserving unrelated tables unless dropDatabase is requested.
     */
    public function sync(
        DatabaseInterface $source,
        array $options = []
    ): self {
        $schema = $source->schema();
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $tables[$table->getName()] = [];
        }
        $this->loadTables($tables, $options, $schema);
        $this->schema = null;

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

        $this->schema = null;

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

        $format = $this->requireString($format, 'format');

        // Load the source spreadsheet from the dump.
        $sourceSpreadsheet = $this->loader->loadFromString($source, $format);

        // Load the source spreadsheet into the current connection.
        return $this->load($sourceSpreadsheet, $options);
    }

    /**
     * {@inheritDoc}
     *
     * Validate all rows before applying any change. Existing tables require
     * complete rows; declared or inferred primary keys upsert with strict value comparison,
     * while tables without a primary key append every row. Duplicate incoming
     * keys and missing/null key components are rejected.
     *
     * dropDatabase replaces the document, dropTables recreates included tables,
     * and dropData replaces their rows while keeping their schema, in that
     * precedence order. Other tables are preserved unless dropDatabase is set.
     * The return value counts incoming rows inserted or updated.
     */
    protected function loadFromArray(array $source, array $options = []): int
    {
        return $this->loadTables($source, $options);
    }

    /**
     * Share row validation, staging and replacement policies across sources.
     * Source schemas supply the definitions of new or recreated tables.
     *
     * @param array<array-key, mixed> $source
     * @param array<array-key, mixed> $options
     */
    private function loadTables(array $source, array $options, ?SchemaInterface $sourceSchema = null): int
    {
        $dropDatabase = $this->requireBool($options['dropDatabase'] ?? false, 'dropDatabase');
        $dropTables = $this->requireBool($options['dropTables'] ?? false, 'dropTables');
        $dropData = $this->requireBool($options['dropData'] ?? false, 'dropData');
        $schemaSheetName = $this->requireString($options['schemaSheetName'] ?? '__schema', 'schemaSheetName');
        $currentSchema = $dropDatabase ? new Schema() : $this->schema();
        $nextSchema = new Schema($currentSchema->getName());
        foreach ($currentSchema->getTables() as $table) {
            $nextSchema->addTable($table);
        }

        // Stage every sheet and the schema before changing the connection.
        $sheets = [];
        $schemaChanged = $dropDatabase;
        $rowsLoaded = 0;
        foreach ($source as $name => $rows) {
            $name = (string) $name;
            if ($name === '' || $name === $schemaSheetName) {
                throw new InvalidArgumentException([
                    'Table name must be non-empty and different from the schema sheet name: "{table}".',
                    'table' => $name,
                ]);
            }
            if (!is_array($rows)) {
                throw new InvalidArgumentException([
                    'Rows for table "{table}" must be an array; {type} given.',
                    'table' => $name,
                    'type' => get_debug_type($rows),
                ]);
            }
            $table = $dropTables ? null : $currentSchema->getTable($name);
            $sourceTable = $sourceSchema?->getTable($name);
            if ($table !== null && $table->getColumns() !== [] && $sourceTable !== null
                && $this->tableDefinition($table) !== $this->tableDefinition($sourceTable)
            ) {
                throw new InvalidArgumentException([
                    'Source and destination schemas are incompatible for table "{table}".',
                    'table' => $name,
                ]);
            }
            $validationTable = $table !== null && $table->getColumns() !== [] ? $table : $sourceTable;
            $validated = $this->validateArrayRows($rows, $name, $validationTable);
            if ($sourceTable !== null && ($table === null || $table->getColumns() === [])) {
                $table = $sourceTable;
                $nextSchema->addTable($table);
                $schemaChanged = true;
            } elseif ($table === null || ($table->getColumns() === [] && $validated !== [])) {
                $primaryKey = $table?->getPrimaryKey() ?? [];
                $table = new Table($name);
                if ($validated !== []) {
                    foreach (array_keys($validated[0]) as $column) {
                        $table->addColumn(new Column((string) $column, 'string'));
                    }
                }
                // Match SpreadsheetSchemaSource's rule for inferred schemas.
                // Existing explicit schemas keep their own primary-key policy.
                if ($primaryKey !== []) {
                    $table->setPrimaryKey($primaryKey);
                } elseif ($table->hasColumn('id')) {
                    $table->setPrimaryKey(['id']);
                }
                $nextSchema->addTable($table);
                $schemaChanged = true;
            }
            $existing = [];
            if (!$dropDatabase && !$dropTables && !$dropData) {
                $existing = $this->connection->getSheet($name)?->toAssociative()->getRows() ?? [];
            }
            $merged = $this->mergeArrayRows(array_values($existing), $validated, $table);
            $sheets[] = new Sheet($name, $merged, true);
            $rowsLoaded += count($validated);
        }
        if ($schemaChanged) {
            $metadata = (new SpreadsheetSchemaTarget($schemaSheetName))->applySchema($nextSchema)->getSheet($schemaSheetName);
            if ($metadata !== null) {
                $sheets[] = $metadata;
            }
        }
        if ($dropDatabase) {
            $this->connection = new Spreadsheet();
        }
        foreach ($sheets as $sheet) {
            $this->connection->addSheet($sheet);
        }

        return $rowsLoaded;
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return list<array<array-key, mixed>>
     */
    private function validateArrayRows(array $rows, string $name, ?TableInterface $table): array
    {
        $columns = $table === null || $table->getColumns() === []
            ? null
            : array_keys($table->getColumns());
        $validated = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                throw new InvalidArgumentException([
                    'Every row in table "{table}" must be a non-empty array.',
                    'table' => $name,
                ]);
            }
            $columns ??= array_keys($row);
            if (array_diff($columns, array_keys($row)) !== [] || array_diff(array_keys($row), $columns) !== []) {
                throw new InvalidArgumentException([
                    'Every row in table "{table}" must have the same columns.',
                    'table' => $name,
                ]);
            }
            $ordered = [];
            foreach ($columns as $column) {
                $ordered[$column] = $row[$column];
            }
            $validated[] = $ordered;
        }

        return $validated;
    }

    /**
     * @param list<array<array-key, mixed>> $existing
     * @param list<array<array-key, mixed>> $incoming
     * @return list<array<array-key, mixed>>
     */
    private function mergeArrayRows(array $existing, array $incoming, TableInterface $table): array
    {
        $primaryKey = $table->getPrimaryKey();
        if ($primaryKey === []) {
            return array_merge($existing, $incoming);
        }
        $existingKeys = [];
        foreach ($existing as $row) {
            $key = $this->primaryKeyValues($row, $primaryKey, $table->getName());
            if (in_array($key, $existingKeys, true)) {
                throw new InvalidArgumentException([
                    'Duplicate primary key in table "{table}".',
                    'table' => $table->getName(),
                ]);
            }
            $existingKeys[] = $key;
        }
        $incomingKeys = [];
        foreach ($incoming as $row) {
            $key = $this->primaryKeyValues($row, $primaryKey, $table->getName());
            if (in_array($key, $incomingKeys, true)) {
                throw new InvalidArgumentException([
                    'Duplicate primary key in table "{table}".',
                    'table' => $table->getName(),
                ]);
            }
            $incomingKeys[] = $key;
            $position = array_search($key, $existingKeys, true);
            if ($position === false) {
                $existing[] = $row;
                $existingKeys[] = $key;
            } else {
                $existing[$position] = $row;
            }
        }

        return $existing;
    }

    /**
     * Compare complete primary keys strictly, without implicit value conversion.
     *
     * @param array<array-key, mixed> $row
     * @param array<array-key, string> $primaryKey
     * @return list<mixed>
     */
    private function primaryKeyValues(array $row, array $primaryKey, string $table): array
    {
        $values = [];
        foreach ($primaryKey as $column) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                throw new InvalidArgumentException([
                    'Primary key column "{column}" in table "{table}" must be present and non-null.',
                    'column' => $column,
                    'table' => $table,
                ]);
            }
            $values[] = $row[$column];
        }

        return $values;
    }

    private function requireBool(mixed $value, string $option): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException([
                'Option "{option}" must be a boolean; {type} given.',
                'option' => $option,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    protected function loadFromDatabase(
        DatabaseInterface $source,
        array $options = []
    ): int {
        $schema = $source->schema();
        $rows = [];
        foreach ($schema->getTables() as $table) {
            $rows[$table->getName()] = $source->data(['table' => $table->getName()]);
        }

        return $this->loadTables($rows, $options, $schema);
    }

    /**
     * Compare the persisted table definition, independently of column/index
     * declaration order. Metadata timestamps are not part of the definition.
     *
     * @return list<array<array-key, mixed>>
     */
    private function tableDefinition(TableInterface $table): array
    {
        $schema = (new Schema())->addTable($table);
        $rows = (new SpreadsheetSchemaTarget())->applySchema($schema)->getSheet('__schema')?->getRows() ?? [];
        $definition = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($row['type'] ?? null, ['table', 'column', 'index', 'foreign_key'], true)
        ));
        usort($definition, static fn (array $left, array $right): int =>
            [$left['type'], $left['name']] <=> [$right['type'], $right['name']]);

        return $definition;
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
