<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Schema\Source;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\SchemaSourceInterface;
use Derafu\ETL\Schema\Enum\IndexType;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Table;
use Derafu\Spreadsheet\Contract\SheetInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException as RuntimeException;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;

/**
 * Extracts schema information from a Derafu Spreadsheet.
 */
class SpreadsheetSchemaSource implements SchemaSourceInterface
{
    /**
     * Constructor.
     *
     * @param string $schemaSheetName The name of the schema sheet.
     */
    public function __construct(
        private readonly string $schemaSheetName = '__schema'
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function extractSchema(mixed $spreadsheet): SchemaInterface
    {
        // Check if spreadsheet is a valid type.
        if (!$spreadsheet instanceof SpreadsheetInterface) {
            throw new InvalidArgumentException(
                '$spreadsheet must be an instance of SpreadsheetInterface.'
            );
        }

        // If the schema sheet exists, process it.
        $schemaSheet = $spreadsheet->getSheet($this->schemaSheetName);
        if ($schemaSheet !== null) {
            return $this->processSchema($schemaSheet);
        }

        // Otherwise, guess the schema.
        else {
            return $this->guessSchema($spreadsheet);
        }
    }

    /**
     * Process the schema from the schema sheet.
     *
     * @param SheetInterface $schemaSheet The schema sheet.
     * @return SchemaInterface The schema.
     */
    private function processSchema(SheetInterface $schemaSheet): SchemaInterface
    {
        // Create a new schema.
        $schema = new Schema();

        // Process schema metadata.
        $this->processSchemaMetadata($schema, $schemaSheet);

        // Create tables, columns, indexes, and foreign keys from schema sheet.
        $this->processTables($schema, $schemaSheet);

        return $schema;
    }

    /**
     * Guess the schema from the spreadsheet.
     *
     * @param SpreadsheetInterface $spreadsheet The spreadsheet.
     * @return SchemaInterface The schema.
     */
    private function guessSchema(SpreadsheetInterface $spreadsheet): SchemaInterface
    {
        $schema = new Schema();

        foreach ($spreadsheet->getSheets() as $sheet) {
            $table = new Table($sheet->getName());
            $columnNames = $sheet->getColumnNames();
            $columnTypes = [];
            $nColumns = count($columnNames);

            foreach ($sheet->getDataRows() as $row) {
                foreach ($columnNames as $columnName) {
                    if (isset($columnTypes[$columnName])) {
                        continue;
                    }

                    if (isset($row[$columnName])) {
                        $columnTypes[$columnName] = $this->guessColumnType($row[$columnName]);
                    }
                }

                if (count($columnTypes) === $nColumns) {
                    break;
                }
            }

            foreach ($columnNames as $columnName) {
                $column = new Column(
                    (string) $columnName,
                    $columnTypes[$columnName] ?? 'string'
                );
                $table->addColumn($column);
            }

            if ($table->hasColumn('id')) {
                $table->setPrimaryKey(['id']);
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    /**
     * Guess the type of a column.
     *
     * @param mixed $value The value to guess the type of.
     * @return string The type of the value.
     */
    private function guessColumnType(mixed $value): string
    {
        // TODO: Implement this method correctly.
        // IDEA under development, not an approved or final design: infer types
        // from all non-null values in a column, using their existing PHP types
        // without parsing strings or casting values (casting belongs to Caster).
        // Consider string as a fallback for empty or incompatible mixed columns;
        // the integer/float combination still needs a policy. Share inference
        // with array-created tables and preserve explicit schemas and key rules.
        // Agree on the policy and add real regression tests before implementing.

        return 'string';
    }

    /**
     * Process schema metadata.
     *
     * @param SchemaInterface $schema The schema to populate.
     * @param SheetInterface $schemaSheet The schema sheet.
     */
    private function processSchemaMetadata(
        SchemaInterface $schema,
        SheetInterface $schemaSheet
    ): void {
        $rows = $schemaSheet->getDataRows();

        foreach ($rows as $row) {
            if ($row['type'] === 'metadata' && $row['name'] === 'schema') {
                $properties = $this->requireArray($row['properties'] ?? [], 'metadata.properties');
                if (isset($properties['name'])) {
                    $schema->setName($this->requireString($properties['name'], 'metadata.name'));
                }
            }
        }
    }

    /**
     * Process tables and their components.
     *
     * @param SchemaInterface $schema The schema to populate.
     * @param SheetInterface $schemaSheet The schema sheet.
     */
    private function processTables(
        SchemaInterface $schema,
        SheetInterface $schemaSheet
    ): void {
        $rows = $schemaSheet->getDataRows();

        // Group rows by type for easier processing.
        $groupedRows = [
            'table' => [],
            'column' => [],
            'index' => [],
            'foreign_key' => [],
        ];

        // Group rows by type.
        foreach ($rows as $row) {
            $type = $this->requireString($row['type'] ?? null, 'row.type');
            if (isset($groupedRows[$type])) {
                $groupedRows[$type][] = [
                    'name' => $this->requireString($row['name'] ?? null, $type . '.name'),
                    'properties' => $this->requireArray($row['properties'] ?? [], $type . '.properties'),
                ];
            }
        }

        // Process tables first.
        foreach ($groupedRows['table'] as $tableRow) {
            $table = new Table($tableRow['name']);
            $table->setPlatformOptions($this->requireArray($tableRow['properties']['platform_options'] ?? [], 'table.platform_options'));

            // Set primary key if available.
            if (isset($tableRow['properties']['primary_key'])) {
                $table->setPrimaryKey($this->requireStringArray($tableRow['properties']['primary_key'], 'table.primary_key'));
            }

            $schema->addTable($table);
        }

        // Then process columns.
        foreach ($groupedRows['column'] as $columnRow) {
            $nameParts = explode('.', $columnRow['name']);
            if (count($nameParts) !== 2) {
                throw new RuntimeException([
                    'Invalid column name format: "{name}". Must be in the format "table.column".',
                    'name' => $columnRow['name'],
                ]);
            }

            $tableName = $nameParts[0];
            $columnName = $nameParts[1];
            $properties = $columnRow['properties'];

            $table = $schema->getTable($tableName);
            if ($table === null) {
                throw new RuntimeException([
                    'Table "{table}" not found in schema sheet.',
                    'table' => $tableName,
                ]);
            }

            $column = new Column($columnName, $this->requireString($properties['type'] ?? 'string', 'column.type'));

            $column->setPlatformOptions($this->requireArray($properties['platform_options'] ?? [], 'column.platform_options'));

            // Set nullable property.
            $column->setNullable($this->requireBool($properties['nullable'] ?? true, 'column.nullable'));

            // Set length property.
            if (isset($properties['length'])) {
                $column->setLength($this->requireInt($properties['length'], 'column.length'));
            }

            // Set precision property.
            if (isset($properties['precision'])) {
                $column->setPrecision($this->requireInt($properties['precision'], 'column.precision'));

                if (isset($properties['scale'])) {
                    $column->setScale($this->requireInt($properties['scale'], 'column.scale'));
                }
            }

            // Set default property.
            if (isset($properties['default'])) {
                $column->setDefault($properties['default']);
            }

            // Add column to table.
            $table->addColumn($column);
        }

        // Process indexes.
        foreach ($groupedRows['index'] as $indexRow) {
            $nameParts = explode('.', $indexRow['name']);
            if (count($nameParts) !== 2) {
                throw new RuntimeException([
                    'Invalid index name format: "{name}". Must be in the format "table.index".',
                    'name' => $indexRow['name'],
                ]);
            }

            $tableName = $nameParts[0];
            $indexName = $nameParts[1];
            $properties = $indexRow['properties'];

            $table = $schema->getTable($tableName);
            if ($table === null) {
                throw new RuntimeException([
                    'Table "{table}" not found in schema sheet.',
                    'table' => $tableName,
                ]);
            }

            if (!isset($properties['columns']) || !is_array($properties['columns'])) {
                throw new RuntimeException([
                    'Missing columns for index "{index}" in table "{table}".',
                    'index' => $indexName,
                    'table' => $tableName,
                ]);
            }

            $table->addIndex($this->createIndex(
                $indexName,
                $tableName,
                $properties
            ));
        }

        // Process foreign keys.
        foreach ($groupedRows['foreign_key'] as $fkRow) {
            $nameParts = explode('.', $fkRow['name']);
            if (count($nameParts) !== 2) {
                throw new RuntimeException([
                    'Invalid foreign key name format: "{name}". Must be in the format "table.foreign_key".',
                    'name' => $fkRow['name'],
                ]);
            }

            $tableName = $nameParts[0];
            $fkName = $nameParts[1];
            $properties = $fkRow['properties'];

            $table = $schema->getTable($tableName);
            if ($table === null) {
                throw new RuntimeException([
                    'Table "{table}" not found in schema sheet.',
                    'table' => $tableName,
                ]);
            }

            if (
                !is_array($properties['local_columns'] ?? null)
                || !isset($properties['foreign_table'])
                || !is_array($properties['foreign_columns'] ?? null)
            ) {
                throw new RuntimeException([
                    'Missing required properties for foreign key "{foreign_key}" in table "{table}".',
                    'foreign_key' => $fkName,
                    'table' => $tableName,
                ]);
            }

            $foreignKey = new ForeignKey(
                $this->requireString($properties['foreign_table'], 'foreign_key.foreign_table'),
                $this->requireStringArray($properties['local_columns'], 'foreign_key.local_columns'),
                $this->requireStringArray($properties['foreign_columns'], 'foreign_key.foreign_columns'),
                $fkName
            );

            if (isset($properties['on_delete'])) {
                $foreignKey->setOnDelete($this->requireString($properties['on_delete'], 'foreign_key.on_delete'));
            }

            if (isset($properties['on_update'])) {
                $foreignKey->setOnUpdate($this->requireString($properties['on_update'], 'foreign_key.on_update'));
            }

            $table->addForeignKey($foreignKey);
        }
    }

    /**
     * Create an index from the properties of a row of the schema sheet.
     *
     * Besides the current format (`type` and `clustered`) it reads the legacy
     * one (`unique` and `flags`), so spreadsheets generated by previous
     * versions can still be loaded.
     *
     * @param string $indexName The index name.
     * @param string $tableName The table name.
     * @param array<array-key, mixed> $properties The properties of the index row.
     * @return Index
     */
    private function createIndex(
        string $indexName,
        string $tableName,
        array $properties
    ): Index {
        $type = IndexType::REGULAR;
        $clustered = (bool) ($properties['clustered'] ?? false);

        if (isset($properties['type'])) {
            $type = IndexType::tryFrom($this->requireString($properties['type'], 'index.type'))
                ?? throw new RuntimeException([
                    'Invalid type "{type}" for index "{index}" in table "{table}".',
                    'type' => $properties['type'],
                    'index' => $indexName,
                    'table' => $tableName,
                ])
            ;
        } elseif ($properties['unique'] ?? false) {
            $type = IndexType::UNIQUE;
        }

        foreach ($this->requireArray($properties['flags'] ?? [], 'index.flags') as $flag) {
            if ($flag === 'clustered') {
                $clustered = true;
            } elseif (in_array($flag, ['fulltext', 'spatial'], true)) {
                $type = IndexType::from($flag);
            } else {
                throw new RuntimeException([
                    'Unsupported flag "{flag}" for index "{index}" in table "{table}".',
                    'flag' => $flag,
                    'index' => $indexName,
                    'table' => $tableName,
                ]);
            }
        }

        return new Index($indexName, $this->requireStringArray($properties['columns'], 'index.columns'), $type, $clustered);
    }

    private function requireString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new RuntimeException([
                'Schema field "{field}" must be a string; {type} given.',
                'field' => $field,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    private function requireInt(mixed $value, string $field): int
    {
        if (!is_int($value)) {
            throw new RuntimeException([
                'Schema field "{field}" must be an integer; {type} given.',
                'field' => $field,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    private function requireBool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException([
                'Schema field "{field}" must be a boolean; {type} given.',
                'field' => $field,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function requireArray(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new RuntimeException([
                'Schema field "{field}" must be an array; {type} given.',
                'field' => $field,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }

    /** @return array<array-key, string> */
    private function requireStringArray(mixed $value, string $field): array
    {
        $values = $this->requireArray($value, $field);
        $validated = [];
        foreach ($values as $key => $item) {
            $validated[$key] = $this->requireString($item, $field);
        }

        return $validated;
    }
}
