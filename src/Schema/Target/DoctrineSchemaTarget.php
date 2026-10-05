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
use Derafu\ETL\Schema\Enum\IndexType;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Doctrine\DBAL\Schema\Column as DoctrineColumn;
use Doctrine\DBAL\Schema\Index as DoctrineIndex;
use Doctrine\DBAL\Schema\Index\IndexType as DoctrineIndexType;
use Doctrine\DBAL\Schema\IndexEditor;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use Doctrine\DBAL\Types\Type as DoctrineType;

/**
 * Converts a schema to a Doctrine DBAL Schema.
 */
final class DoctrineSchemaTarget implements SchemaTargetInterface
{
    /**
     * Map of type names to Doctrine DBAL type names.
     *
     * Here we only define the types that are not in the Doctrine DBAL types
     * map. The rest are mapped using the Doctrine DBAL types map in the
     * constructor.
     *
     * @var array<string, string>
     */
    private array $typeMap = [
        'date_mutable' => 'date',
        'datetime_mutable' => 'datetime',
        'datetimetz_mutable' => 'datetimetz',
        'time_mutable' => 'time',
    ];

    /**
     * Constructor.
     */
    public function __construct()
    {
        $doctrineTypes = DoctrineType::getTypesMap();

        foreach ($doctrineTypes as $type => $className) {
            $this->typeMap[$type] = $type;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function applySchema(SchemaInterface $schema): DoctrineSchema
    {
        // Convert tables.
        $doctrineTables = [];
        foreach ($schema->getTables() as $table) {
            $doctrineTables[] = $this->createDoctrineTable($table);
        }

        return new DoctrineSchema($doctrineTables);
    }

    /**
     * Create a Doctrine table from a table of the schema.
     *
     * @param TableInterface $table The table to convert.
     * @return DoctrineTable The created Doctrine table.
     */
    private function createDoctrineTable(TableInterface $table): DoctrineTable
    {
        $tableName = $table->getName();
        $doctrineTable = new DoctrineTable($tableName);

        // Add columns.
        foreach ($table->getColumns() as $column) {
            $this->addColumnToDoctrineTable($doctrineTable, $column);
        }

        // Add primary key.
        $primaryKey = $table->getPrimaryKey();
        if (!empty($primaryKey)) {
            $doctrineTable->setPrimaryKey($primaryKey);
        }

        // Add foreign keys.
        // We'll defer these to add them after all tables are created.
        foreach ($table->getForeignKeys() as $foreignKey) {
            $this->addForeignKeyToDoctrineTable($doctrineTable, $foreignKey);
        }

        // Add indexes.
        $indexes = $table->getIndexes();
        if (!empty($indexes)) {
            $editor = $doctrineTable->edit();
            foreach ($indexes as $index) {
                $editor->addIndex($this->createIndexEditor($index));
            }
            $doctrineTable = $editor->create();
        }

        return $doctrineTable;
    }

    /**
     * Add a column to a Doctrine table.
     *
     * @param DoctrineTable $doctrineTable The Doctrine table.
     * @param ColumnInterface $column The column to add.
     * @return DoctrineColumn The created Doctrine column.
     */
    private function addColumnToDoctrineTable(
        DoctrineTable $doctrineTable,
        ColumnInterface $column
    ): DoctrineColumn {
        $name = $column->getName();
        $type = $this->mapTypeToDoctrineType($column->getType());

        $options = [
            'notnull' => !$column->isNullable(),
        ];

        if ($column->getDefault() !== null) {
            $options['default'] = $column->getDefault();
        }

        if ($column->getLength() !== null) {
            $options['length'] = $column->getLength();
        }

        if ($column->getPrecision() !== null) {
            $options['precision'] = $column->getPrecision();

            if ($column->getScale() !== null) {
                $options['scale'] = $column->getScale();
            }
        }

        $doctrineTable->addColumn($name, $type, $options);

        return $doctrineTable->getColumn($name);
    }

    /**
     * Add a foreign key to a Doctrine table.
     *
     * @param DoctrineTable $doctrineTable The Doctrine table.
     * @param ForeignKeyInterface $foreignKey The foreign key to add.
     * @return DoctrineTable The created Doctrine foreign key.
     */
    private function addForeignKeyToDoctrineTable(
        DoctrineTable $doctrineTable,
        ForeignKeyInterface $foreignKey
    ): DoctrineTable {
        if (empty($foreignKey->getLocalColumns()) || empty($foreignKey->getForeignColumns())) {
            throw new InvalidArgumentException(
                'Foreign key must have local and foreign columns.'
            );
        }

        $options = [];

        if ($foreignKey->getOnDelete() !== null) {
            $options['onDelete'] = $foreignKey->getOnDelete();
        }

        if ($foreignKey->getOnUpdate() !== null) {
            $options['onUpdate'] = $foreignKey->getOnUpdate();
        }

        $name = $foreignKey->getName();

        return $doctrineTable->addForeignKeyConstraint(
            $foreignKey->getForeignTableName(),
            $foreignKey->getLocalColumns(),
            $foreignKey->getForeignColumns(),
            $options,
            $name
        );
    }

    /**
     * Create the Doctrine index editor that describes an index.
     *
     * @param IndexInterface $index The index to describe.
     * @return IndexEditor
     */
    private function createIndexEditor(IndexInterface $index): IndexEditor
    {
        $columns = $index->getColumns();

        if (empty($columns)) {
            throw new InvalidArgumentException('Index must have columns.');
        }

        return DoctrineIndex::editor()
            ->setUnquotedName($index->getName())
            ->setType(match ($index->getType()) {
                IndexType::REGULAR => DoctrineIndexType::REGULAR,
                IndexType::UNIQUE => DoctrineIndexType::UNIQUE,
                IndexType::FULLTEXT => DoctrineIndexType::FULLTEXT,
                IndexType::SPATIAL => DoctrineIndexType::SPATIAL,
            })
            ->setUnquotedColumnNames(...$columns)
            ->setIsClustered($index->isClustered())
        ;
    }

    /**
     * Map our schema type to a Doctrine DBAL type.
     *
     * @param string $type Our schema type.
     * @return string Doctrine DBAL type.
     */
    private function mapTypeToDoctrineType(string $type): string
    {
        $lowerType = strtolower($type);

        return $this->typeMap[$lowerType] ?? 'string';
    }
}
