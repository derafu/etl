<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Schema\Target;

use Derafu\ETL\Schema\Contract\ColumnInterface;
use Derafu\ETL\Schema\Contract\ForeignKeyInterface;
use Derafu\ETL\Schema\Contract\IndexInterface;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\SchemaTargetInterface;
use Derafu\ETL\Schema\Contract\TableInterface;

/**
 * Generates SQLite SQL statements for a database schema.
 */
final class SqliteSchemaTarget implements SchemaTargetInterface
{
    /**
     * Constructor.
     *
     * @param bool $includeDropStatements Whether to include DROP statements before CREATE statements.
     * @param bool $formatOutput Whether to format the output with indentation and newlines.
     */
    public function __construct(
        private readonly bool $includeDropStatements = true,
        private readonly bool $formatOutput = true
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function applySchema(SchemaInterface $schema): string
    {
        $output = '';

        // Add a header comment.
        $schemaName = $schema->getName() ?? 'Database';
        $output .= "-- SQLite schema for {$schemaName}\n";
        $output .= "-- Generated on " . date('Y-m-d H:i:s') . "\n\n";

        // Begin transaction for faster execution.
        $output .= "BEGIN TRANSACTION;\n\n";

        // Process tables in a specific order to respect foreign key constraints.
        $tables = $this->orderTablesForCreation($schema->getTables());

        // Generate drop statements if requested.
        if ($this->includeDropStatements) {
            $output .= "-- Drop existing tables\n";
            foreach (array_reverse($tables) as $table) {
                $output .= "DROP TABLE IF EXISTS `{$table->getName()}`;\n";
            }
            $output .= "\n";
        }

        // Generate create statements for each table.
        $output .= "-- Create tables\n";
        foreach ($tables as $table) {
            $output .= $this->generateCreateTable($table);
            $output .= "\n";
        }

        // Generate indexes.
        $output .= "-- Create indexes\n";
        foreach ($tables as $table) {
            foreach ($table->getIndexes() as $index) {
                // Skip primary key indexes as they are already defined in table creation.
                if ($this->isPrimaryKeyIndex($index, $table)) {
                    continue;
                }

                $output .= $this->generateCreateIndex($index, $table);
                $output .= "\n";
            }
        }

        // Commit transaction.
        $output .= "COMMIT;\n";

        return $output;
    }

    /**
     * Order tables for creation based on their dependencies.
     *
     * This ensures that tables are created before the tables that reference them.
     *
     * @param TableInterface[] $tables The tables to order
     * @return TableInterface[] Ordered tables
     */
    private function orderTablesForCreation(array $tables): array
    {
        // Build dependency graph.
        $dependencies = [];
        foreach ($tables as $table) {
            $tableName = $table->getName();
            $dependencies[$tableName] = [];

            foreach ($table->getForeignKeys() as $foreignKey) {
                $dependencies[$tableName][] = $foreignKey->getForeignTableName();
            }
        }

        // Create lookup for quick access.
        $tableMap = [];
        foreach ($tables as $table) {
            $tableMap[$table->getName()] = $table;
        }

        // Sort based on dependencies.
        $orderedNames = $this->topologicalSort($dependencies);

        // Create final ordered array.
        $orderedTables = [];
        foreach ($orderedNames as $name) {
            if (isset($tableMap[$name])) {
                $orderedTables[] = $tableMap[$name];
            }
        }

        return $orderedTables;
    }

    /**
     * Perform a topological sort on a dependency graph.
     *
     * @param array $graph Dependency graph where keys are nodes and values are
     * arrays of dependencies.
     * @return array Sorted node names.
     */
    private function topologicalSort(array $graph): array
    {
        $result = [];
        $visited = [];
        $temp = [];

        // Visit all nodes.
        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                $this->topologicalSortVisit(
                    $node,
                    $graph,
                    $visited,
                    $temp,
                    $result
                );
            }
        }

        return array_reverse($result);
    }

    /**
     * Helper function for topological sort.
     *
     * @param string $node Current node.
     * @param array $graph Dependency graph.
     * @param array &$visited Visited nodes.
     * @param array &$temp Temporary mark for cycle detection.
     * @param array &$result Resulting sort.
     */
    private function topologicalSortVisit(
        string $node,
        array $graph,
        array &$visited,
        array &$temp,
        array &$result
    ): void {
        // Check for cycles.
        if (isset($temp[$node])) {
            // We have a cycle, but we'll continue.
            return;
        }

        if (!isset($visited[$node])) {
            // Mark temporarily.
            $temp[$node] = true;

            // Visit dependencies.
            foreach ($graph[$node] as $dependency) {
                if (isset($graph[$dependency]) && !isset($visited[$dependency])) {
                    $this->topologicalSortVisit($dependency, $graph, $visited, $temp, $result);
                }
            }

            // Mark visited.
            $visited[$node] = true;
            unset($temp[$node]);

            // Add to result.
            $result[] = $node;
        }
    }

    /**
     * Generate a CREATE TABLE statement for a table.
     *
     * @param TableInterface $table The table to create.
     * @return string SQL statement.
     */
    private function generateCreateTable(TableInterface $table): string
    {
        $tableName = $table->getName();
        $output = "CREATE TABLE `{$tableName}` (\n";

        // Collect column definitions.
        $columnDefs = [];
        foreach ($table->getColumns() as $column) {
            $columnDefs[] = $this->formatOutput('  ')
                . $this->generateColumnDefinition($column, $table)
            ;
        }

        // Add primary key constraint if defined.
        $primaryKey = $table->getPrimaryKey();
        if (!empty($primaryKey)) {
            // Check if primary key is a single column that already has the constraint.
            $primaryKeyAlreadyDefined = false;
            if (count($primaryKey) === 1) {
                $pkColumn = $table->getColumn($primaryKey[0]);
                if (
                    $pkColumn
                    && stripos($this->generateColumnDefinition($pkColumn, $table), 'PRIMARY KEY') !== false
                ) {
                    $primaryKeyAlreadyDefined = true;
                }
            }

            if (!$primaryKeyAlreadyDefined) {
                $pkColumns = array_map(fn ($col) => "`{$col}`", $primaryKey);

                $columnDefs[] = $this->formatOutput('  ')
                    . "PRIMARY KEY (" . implode(', ', $pkColumns) . ")"
                ;
            }
        }

        // Add foreign key constraints.
        foreach ($table->getForeignKeys() as $foreignKey) {
            $columnDefs[] = $this->formatOutput('  ')
                . $this->generateForeignKeyConstraint($foreignKey)
            ;
        }

        // Combine all definitions.
        $output .= implode(",\n", $columnDefs);
        $output .= "\n)";

        // Add WITHOUT ROWID for tables with explicit primary keys (optimization).
        if (!empty($primaryKey)) {
            $output .= " WITHOUT ROWID";
        }

        $output .= ";\n";

        return $output;
    }

    /**
     * Generate a column definition for a CREATE TABLE statement.
     *
     * @param ColumnInterface $column The column to define.
     * @param TableInterface $table The table this column belongs to.
     * @return string Column definition.
     */
    private function generateColumnDefinition(
        ColumnInterface $column,
        TableInterface $table
    ): string {
        $name = $column->getName();
        $type = $this->mapColumnTypeToSqlite($column);

        $definition = "`{$name}` {$type}";

        // Add NOT NULL constraint if needed.
        if (!$column->isNullable()) {
            $definition .= " NOT NULL";
        }

        // Add PRIMARY KEY constraint if this is the only primary key column.
        $primaryKey = $table->getPrimaryKey();
        if (count($primaryKey) === 1 && $primaryKey[0] === $name) {
            $definition .= " PRIMARY KEY";
        }

        // Add DEFAULT value if defined.
        if ($column->getDefault() !== null) {
            $default = $column->getDefault();
            if (is_string($default)) {
                $default = "'" . str_replace("'", "''", $default) . "'";
            } elseif (is_bool($default)) {
                $default = $default ? '1' : '0';
            }

            $definition .= " DEFAULT {$default}";
        }

        return $definition;
    }

    /**
     * Map a column's type to the appropriate SQLite type.
     *
     * @param ColumnInterface $column The column.
     * @return string SQLite type.
     */
    private function mapColumnTypeToSqlite(ColumnInterface $column): string
    {
        $type = strtolower($column->getType());

        // SQLite uses a simplified type system.
        switch ($type) {
            case 'integer':
            case 'smallint':
            case 'bigint':
                return 'INTEGER';

            case 'decimal':
            case 'float':
            case 'double':
                return 'REAL';

            case 'boolean':
                return 'INTEGER'; // 0 or 1.

            case 'date':
            case 'datetime':
            case 'datetimetz':
            case 'time':
                return 'TEXT'; // ISO8601 strings.

            case 'guid':
                return 'TEXT';

            case 'blob':
            case 'binary':
                return 'BLOB';

            default:
                return 'TEXT';
        }
    }

    /**
     * Generate a foreign key constraint for a CREATE TABLE statement.
     *
     * @param ForeignKeyInterface $foreignKey The foreign key to define.
     * @return string Foreign key constraint.
     */
    private function generateForeignKeyConstraint(ForeignKeyInterface $foreignKey): string
    {
        $localColumns = array_map(fn ($col) => "`{$col}`", $foreignKey->getLocalColumns());

        $foreignTable = $foreignKey->getForeignTableName();

        $foreignColumns = array_map(fn ($col) => "`{$col}`", $foreignKey->getForeignColumns());

        $constraint = "FOREIGN KEY (" . implode(', ', $localColumns) . ") ";
        $constraint .= "REFERENCES `{$foreignTable}` (" . implode(', ', $foreignColumns) . ")";

        // Add ON DELETE action if defined.
        if ($foreignKey->getOnDelete() !== null) {
            $constraint .= " ON DELETE " . $foreignKey->getOnDelete();
        }

        // Add ON UPDATE action if defined.
        if ($foreignKey->getOnUpdate() !== null) {
            $constraint .= " ON UPDATE " . $foreignKey->getOnUpdate();
        }

        return $constraint;
    }

    /**
     * Generate a CREATE INDEX statement.
     *
     * @param IndexInterface $index The index to create.
     * @param TableInterface $table The table this index belongs to.
     * @return string SQL statement.
     */
    private function generateCreateIndex(
        IndexInterface $index,
        TableInterface $table
    ): string {
        $tableName = $table->getName();
        $indexName = $index->getName();

        // Unique index?
        $unique = $index->isUnique() ? 'UNIQUE ' : '';

        // Column list.
        $columns = array_map(fn ($col) => "`{$col}`", $index->getColumns());

        return "CREATE {$unique}INDEX `{$indexName}` ON `{$tableName}` (" . implode(', ', $columns) . ");\n";
    }

    /**
     * Check if an index is for a primary key.
     *
     * @param IndexInterface $index The index to check.
     * @param TableInterface $table The table this index belongs to.
     * @return bool True if the index is for a primary key.
     */
    private function isPrimaryKeyIndex(
        IndexInterface $index,
        TableInterface $table
    ): bool {
        $primaryKey = $table->getPrimaryKey();
        if (empty($primaryKey)) {
            return false;
        }

        // Check if columns match.
        $indexColumns = $index->getColumns();
        sort($indexColumns);

        $primaryKey = array_values($primaryKey); // ensure numeric keys.
        sort($primaryKey);

        return $indexColumns === $primaryKey;
    }

    /**
     * Format output with indentation if enabled.
     *
     * @param string $text The text to format.
     * @return string Formatted text.
     */
    private function formatOutput(string $text): string
    {
        return $this->formatOutput ? $text : '';
    }
}
