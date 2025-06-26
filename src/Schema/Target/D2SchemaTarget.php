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

use Derafu\ETL\Schema\Contract\ForeignKeyInterface;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\SchemaTargetInterface;
use Derafu\ETL\Schema\Contract\TableInterface;

/**
 * Generates a D2 diagram representation of a database schema.
 */
final class D2SchemaTarget implements SchemaTargetInterface
{
    /**
     * Detail level full.
     */
    public const DETAIL_FULL = 'full';

    /**
     * Detail level keys only.
     */
    public const DETAIL_KEYS_ONLY = 'keys_only';

    /**
     * Detail level minimal.
     */
    public const DETAIL_MINIMAL = 'minimal';

    /**
     * Layout minimal.
     */
    public const LAYOUT_MINIMAL = 'minimal';

    /**
     * Direction up.
     */
    public const DIRECTION_UP = 'up';

    /**
     * Direction down.
     */
    public const DIRECTION_DOWN = 'down';

    /**
     * Direction left.
     */
    public const DIRECTION_LEFT = 'left';

    /**
     * Direction right.
     */
    public const DIRECTION_RIGHT = 'right';

    /**
     * Layout default.
     */
    public const LAYOUT_DEFAULT = 'default';

    /**
     * Layout clustered.
     */
    public const LAYOUT_CLUSTERED = 'clustered';

    /**
     * Layout hierarchical.
     */
    public const LAYOUT_HIERARCHICAL = 'hierarchical';

    /**
     * Detail level.
     *
     * @var self::DETAIL_*
     */
    private string $detailLevel;

    /**
     * Direction.
     *
     * @var self::DIRECTION_*
     */
    private string $direction;

    /**
     * Layout.
     *
     * @var self::LAYOUT_*
     */
    private string $layout;

    /**
     * Whether to include indexes in the diagram.
     *
     * @var bool
     */
    private bool $includeIndexes;

    /**
     * Table filter.
     *
     * @var array|null
     */
    private ?array $tableFilter;

    /**
     * Constructor.
     *
     * @param string $detailLevel Level of detail to include (one of the DETAIL_* constants).
     * @param string $direction Direction of the diagram (one of the DIRECTION_* constants).
     * @param string $layout Layout style to use (one of the LAYOUT_* constants).
     * @param bool $includeIndexes Whether to include indexes in the diagram.
     * @param array|null $tableFilter Array of table names to include, or null for all tables.
     */
    public function __construct(
        string $detailLevel = self::DETAIL_FULL,
        string $direction = self::DIRECTION_RIGHT,
        string $layout = self::LAYOUT_DEFAULT,
        bool $includeIndexes = false,
        ?array $tableFilter = null
    ) {
        $this->detailLevel = $detailLevel;
        $this->direction = $direction;
        $this->layout = $layout;
        $this->includeIndexes = $includeIndexes;
        $this->tableFilter = $tableFilter;
    }

    /**
     * {@inheritDoc}
     */
    public function applySchema(SchemaInterface $schema): string
    {
        return $this->generateERDiagram($schema);
    }

    /**
     * Generate an entity-relationship diagram for the schema.
     *
     * @param SchemaInterface $schema The schema to diagram.
     * @return string D2 code for the diagram.
     */
    private function generateERDiagram(SchemaInterface $schema): string
    {
        $output = "# " . ($schema->getName() ?? "Database Schema") . "\n\n";

        // Add diagram direction and layout.
        $output .= "direction: {$this->direction}\n";

        if ($this->layout === self::LAYOUT_HIERARCHICAL) {
            $output .= "layout: elk.layered { elk.direction: DOWN }\n";
        }

        $output .= "\n";

        // Define table styles.
        // $output .= "# Styles\n";
        // $output .= "table: {\n";
        // $output .= "  shape: sql_table\n";
        // $output .= "  style: {\n";
        // $output .= "    fill: \"#f5f5f5\"\n";
        // $output .= "    border-radius: 4\n";
        // $output .= "  }\n";
        // $output .= "}\n\n";

        // $output .= "pk: {\n";
        // $output .= "  style: {\n";
        // $output .= "    fill: \"#e0f0ff\"\n";
        // $output .= "    bold: true\n";
        // $output .= "  }\n";
        // $output .= "}\n\n";

        // $output .= "fk: {\n";
        // $output .= "  style: {\n";
        // $output .= "    fill: \"#f0e0ff\"\n";
        // $output .= "    italic: true\n";
        // $output .= "  }\n";
        // $output .= "}\n\n";

        // if ($this->includeIndexes) {
        //     $output .= "idx: {\n";
        //     $output .= "  style: {\n";
        //     $output .= "    fill: \"#f0fff0\"\n";
        //     $output .= "    stroke-dash: 2\n";
        //     $output .= "  }\n";
        //     $output .= "}\n\n";
        // }

        // Define tables.
        $output .= "# Tables\n";

        // Filter tables if necessary.
        $tables = $schema->getTables();
        if ($this->tableFilter !== null) {
            $tables = array_filter($tables, fn ($table) => in_array($table->getName(), $this->tableFilter, true));
        }

        // Add tables to the diagram.
        foreach ($tables as $table) {
            $output .= $this->generateTableDefinition($table);
        }

        // Add relationships.
        $output .= "\n# Relationships\n";

        foreach ($tables as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                // Skip if the referenced table is not in our filtered set.
                if (
                    $this->tableFilter !== null
                    && !in_array($foreignKey->getForeignTableName(), $this->tableFilter, true)
                ) {
                    continue;
                }

                $output .= $this->generateRelationship($table, $foreignKey);
            }
        }

        // Add clusters if using clustered layout.
        if ($this->layout === self::LAYOUT_CLUSTERED) {
            $output .= "\n# Clusters\n";

            // In a real implementation, you would analyze table relationships
            // and group related tables together. For simplicity, we'll just
            // create a basic example.

            $clusters = $this->identifyClusters($tables);
            foreach ($clusters as $name => $tableNames) {
                if (count($tableNames) > 1) {
                    $output .= "{$name}: {\n";
                    $output .= "  " . implode(" ", $tableNames) . "\n";
                    $output .= "  style: {\n";
                    $output .= "    fill: \"#f9f9f9\"\n";
                    $output .= "    stroke: \"#dddddd\"\n";
                    $output .= "  }\n";
                    $output .= "}\n";
                }
            }
        }

        return $output;
    }

    /**
     * Generate D2 code for a table definition.
     *
     * @param TableInterface $table The table to diagram.
     * @return string D2 code for the table.
     */
    private function generateTableDefinition(TableInterface $table): string
    {
        $tableName = $table->getName();
        $output = "{$tableName}: " . ($this->detailLevel === self::DETAIL_MINIMAL ? "{}\n" : "{\n");

        if ($this->detailLevel !== self::DETAIL_MINIMAL) {
            $output .= "  shape: sql_table\n";

            // Get primary and foreign key column names.
            $primaryKeyColumns = $table->getPrimaryKey();
            $foreignKeyColumns = [];
            foreach ($table->getForeignKeys() as $foreignKey) {
                $foreignKeyColumns = array_merge($foreignKeyColumns, $foreignKey->getLocalColumns());
            }
            $foreignKeyColumns = array_unique($foreignKeyColumns);

            // Add columns based for detail level full and keys only.
            foreach ($table->getColumns() as $column) {
                $columnName = $column->getName();

                // Skip non-key columns if we're in keys_only mode.
                if (
                    $this->detailLevel === self::DETAIL_KEYS_ONLY
                    && !in_array($columnName, $primaryKeyColumns, true)
                    && !in_array($columnName, $foreignKeyColumns, true)
                ) {
                    continue;
                }

                $columnType = $column->getType();
                if ($column->getLength() !== null) {
                    $columnType .= "({$column->getLength()})";
                } elseif ($column->getPrecision() !== null) {
                    $precision = (string) $column->getPrecision();
                    if ($column->getScale() !== null) {
                        $precision .= "," . $column->getScale();
                    }
                    $columnType .= "({$precision})";
                }

                // Determine if column is PK, FK, or both.
                $class = 'column';
                if (in_array($columnName, $primaryKeyColumns, true)) {
                    $class = 'pk';
                    if (in_array($columnName, $foreignKeyColumns, true)) {
                        $class = 'pk fk';
                    }
                } elseif (in_array($columnName, $foreignKeyColumns, true)) {
                    $class = 'fk';
                }

                $attributes = [];
                if (!$column->isNullable()) {
                    $attributes[] = 'NOT NULL';
                }

                $attributeText = !empty($attributes) ? " " . implode(" ", $attributes) : "";
                $output .= "  {$columnName}: {$columnType}{$attributeText} {$class}\n";
            }

            // Include indexes if requested
            if ($this->includeIndexes && $this->detailLevel !== self::DETAIL_KEYS_ONLY) {
                $indexes = $table->getIndexes();
                if (!empty($indexes)) {
                    $output .= "  ---\n"; // Separator line
                    $output .= "  # Indexes\n";

                    foreach ($indexes as $index) {
                        $indexName = $index->getName();
                        $indexType = $index->isUnique() ? "UNIQUE" : "INDEX";
                        $indexColumns = implode(", ", $index->getColumns());

                        $output .= "  {$indexName}: {$indexType}({$indexColumns}) idx\n";
                    }
                }
            }

            $output .= "}\n";
        }

        return $output;
    }

    /**
     * Generate D2 code for a relationship.
     *
     * @param TableInterface $table The table containing the foreign key.
     * @param ForeignKeyInterface $foreignKey The foreign key defining the relationship.
     * @return string D2 code for the relationship.
     */
    private function generateRelationship(TableInterface $table, ForeignKeyInterface $foreignKey): string
    {
        $sourceName = $table->getName();
        $targetName = $foreignKey->getForeignTableName();

        // Determine relationship style based on ON DELETE/UPDATE clauses.
        $style = '';
        if ($foreignKey->getOnDelete() === 'CASCADE') {
            $style = ' {';
            $style .= 'style.stroke-dash: 5';

            // Add label if we have space for it.
            if ($this->layout !== self::LAYOUT_MINIMAL) {
                $style .= '; tooltip: "ON DELETE CASCADE"';
            }

            $style .= '}';
        }

        // Create relationship line with arrow.
        $output = "{$sourceName} -> {$targetName}{$style}\n";

        return $output;
    }

    /**
     * Identify clusters of related tables.
     *
     * This is a simple implementation that groups tables based on naming patterns.
     * A more sophisticated implementation would analyze foreign key relationships.
     *
     * @param array $tables Array of TableInterface objects.
     * @return array Associative array of cluster name => array of table names.
     */
    private function identifyClusters(array $tables): array
    {
        $clusters = [];
        $prefixMap = [];

        // Group tables by common prefixes.
        foreach ($tables as $table) {
            $name = $table->getName();
            $parts = explode('_', $name);

            if (count($parts) > 1) {
                $prefix = $parts[0];
                $prefixMap[$prefix][] = $name;
            } else {
                // No prefix, put in its own cluster.
                $clusters["cluster_{$name}"] = [$name];
            }
        }

        // Create clusters from prefix groups.
        foreach ($prefixMap as $prefix => $tableNames) {
            if (count($tableNames) > 1) {
                $clusters["cluster_{$prefix}"] = $tableNames;
            } else {
                // Only one table with this prefix, don't cluster it.
                $clusters["cluster_misc"][] = $tableNames[0];
            }
        }

        return $clusters;
    }
}
