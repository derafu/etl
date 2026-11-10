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

use Derafu\Array\ArrayReader;
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
use Derafu\Value\Exception\TypeMismatchException;
use Derafu\Value\ValueType;

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
                $properties = ArrayReader::fromArray($this->schemaField($row['properties'] ?? [], 'metadata.properties', ValueType::array()));
                if ($properties->hasValue('name')) {
                    $schema->setName($this->schemaField($properties->get('name', ValueType::mixed()), 'metadata.name', ValueType::string()));
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
            $type = $this->schemaField($row['type'] ?? null, 'row.type', ValueType::string());
            if (isset($groupedRows[$type])) {
                $groupedRows[$type][] = [
                    'name' => $this->schemaField($row['name'] ?? null, $type . '.name', ValueType::string()),
                    'properties' => ArrayReader::fromArray($this->schemaField($row['properties'] ?? [], $type . '.properties', ValueType::array())),
                ];
            }
        }

        // Process tables first.
        foreach ($groupedRows['table'] as $tableRow) {
            $table = new Table($tableRow['name']);
            $table->setPlatformOptions($this->schemaField($tableRow['properties']->get('platform_options', ValueType::mixed()) ?? [], 'table.platform_options', ValueType::array()));

            // Set primary key if available.
            if ($tableRow['properties']->hasValue('primary_key')) {
                $table->setPrimaryKey($this->schemaField($tableRow['properties']->get('primary_key', ValueType::mixed()), 'table.primary_key', ValueType::arrayOf(ValueType::string())));
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

            $column = new Column($columnName, $this->schemaField($properties->get('type', ValueType::mixed()) ?? 'string', 'column.type', ValueType::string()));

            $column->setPlatformOptions($this->schemaField($properties->get('platform_options', ValueType::mixed()) ?? [], 'column.platform_options', ValueType::array()));

            // Set nullable property.
            $column->setNullable($this->schemaField($properties->get('nullable', ValueType::mixed()) ?? true, 'column.nullable', ValueType::bool()));

            // Set length property.
            if ($properties->hasValue('length')) {
                $column->setLength($this->schemaField($properties->get('length', ValueType::mixed()), 'column.length', ValueType::int()));
            }

            // Set precision property.
            if ($properties->hasValue('precision')) {
                $column->setPrecision($this->schemaField($properties->get('precision', ValueType::mixed()), 'column.precision', ValueType::int()));

                if ($properties->hasValue('scale')) {
                    $column->setScale($this->schemaField($properties->get('scale', ValueType::mixed()), 'column.scale', ValueType::int()));
                }
            }

            // Set default property.
            if ($properties->hasValue('default')) {
                $column->setDefault($properties->get('default', ValueType::mixed()));
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

            if (!$properties->hasValue('columns') || !is_array($properties->get('columns', ValueType::mixed()))) {
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
                !is_array($properties->get('local_columns', ValueType::mixed()) ?? null)
                || !$properties->hasValue('foreign_table')
                || !is_array($properties->get('foreign_columns', ValueType::mixed()) ?? null)
            ) {
                throw new RuntimeException([
                    'Missing required properties for foreign key "{foreign_key}" in table "{table}".',
                    'foreign_key' => $fkName,
                    'table' => $tableName,
                ]);
            }

            $foreignKey = new ForeignKey(
                $this->schemaField($properties->get('foreign_table', ValueType::mixed()), 'foreign_key.foreign_table', ValueType::string()),
                $this->schemaField($properties->get('local_columns', ValueType::mixed()), 'foreign_key.local_columns', ValueType::indexedOf(ValueType::string())),
                $this->schemaField($properties->get('foreign_columns', ValueType::mixed()), 'foreign_key.foreign_columns', ValueType::indexedOf(ValueType::string())),
                $fkName
            );

            if ($properties->hasValue('on_delete')) {
                $foreignKey->setOnDelete($this->schemaField($properties->get('on_delete', ValueType::mixed()), 'foreign_key.on_delete', ValueType::string()));
            }

            if ($properties->hasValue('on_update')) {
                $foreignKey->setOnUpdate($this->schemaField($properties->get('on_update', ValueType::mixed()), 'foreign_key.on_update', ValueType::string()));
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
     * @param ArrayReader $properties The properties of the index row.
     * @return Index
     */
    private function createIndex(
        string $indexName,
        string $tableName,
        ArrayReader $properties
    ): Index {
        $type = IndexType::REGULAR;
        $clustered = (bool) ($properties->get('clustered', ValueType::mixed()) ?? false);

        if ($properties->hasValue('type')) {
            $type = IndexType::tryFrom($this->schemaField($properties->get('type', ValueType::mixed()), 'index.type', ValueType::string()))
                ?? throw new RuntimeException([
                    'Invalid type "{type}" for index "{index}" in table "{table}".',
                    'type' => $properties->get('type', ValueType::mixed()),
                    'index' => $indexName,
                    'table' => $tableName,
                ])
            ;
        } elseif ($properties->get('unique', ValueType::mixed()) ?? false) {
            $type = IndexType::UNIQUE;
        }

        foreach ($this->schemaField($properties->get('flags', ValueType::mixed()) ?? [], 'index.flags', ValueType::array()) as $flag) {
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

        return new Index($indexName, $this->schemaField($properties->get('columns', ValueType::mixed()), 'index.columns', ValueType::arrayOf(ValueType::string())), $type, $clustered);
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
    private function schemaField(mixed $value, string $field, ValueType $type): mixed
    {
        try {
            return $type->parse($value, path: $field);
        } catch (TypeMismatchException $exception) {
            $details = ArrayReader::fromArray($exception->getTranslatableMessage()->getParameters());
            throw match ($details->requireString('expected')) {
                'string' => new RuntimeException([
                    'Schema field "{field}" must be a string; {type} given.',
                    'field' => $field,
                    'type' => $details->requireString('given'),
                ], previous: $exception),
                'bool' => new RuntimeException([
                    'Schema field "{field}" must be a boolean; {type} given.',
                    'field' => $field,
                    'type' => $details->requireString('given'),
                ], previous: $exception),
                'int', 'int key' => new RuntimeException([
                    'Schema field "{field}" must be an integer; {type} given.',
                    'field' => $field,
                    'type' => $details->requireString('given'),
                ], previous: $exception),
                default => new RuntimeException([
                    'Schema field "{field}" must be an array; {type} given.',
                    'field' => $field,
                    'type' => $details->requireString('given'),
                ], previous: $exception),
            };
        }
    }
}
