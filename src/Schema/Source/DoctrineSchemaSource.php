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
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Table;
use Doctrine\DBAL\Schema\Column as DoctrineColumn;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Types\Type as DoctrineType;
use RuntimeException;

/**
 * Extracts schema information from a Doctrine DBAL Schema object.
 */
final class DoctrineSchemaSource implements SchemaSourceInterface
{
    /**
     * {@inheritDoc}
     */
    public function extractSchema(mixed $source): SchemaInterface
    {
        if (!($source instanceof DoctrineSchema)) {
            throw new RuntimeException(
                'The Doctrine DBAL Schema must be an instance of DoctrineSchema.'
            );
        }

        $schema = new Schema();

        // Extract each table.
        foreach ($source->getTables() as $doctrineTable) {
            $table = new Table($doctrineTable->getName());

            // Extract columns.
            foreach ($doctrineTable->getColumns() as $doctrineColumn) {
                $column = new Column(
                    $doctrineColumn->getName(),
                    $this->getColumnType($doctrineColumn)
                );

                // Set column properties.
                $column->setNullable(!$doctrineColumn->getNotnull());

                if ($doctrineColumn->getDefault() !== null) {
                    $column->setDefault($doctrineColumn->getDefault());
                }

                if ($doctrineColumn->getLength() !== null) {
                    $column->setLength($doctrineColumn->getLength());
                }

                if ($doctrineColumn->getPrecision() !== null) {
                    $column->setPrecision($doctrineColumn->getPrecision());

                    if ($doctrineColumn->getScale() !== null) {
                        $column->setScale($doctrineColumn->getScale());
                    }
                }

                $table->addColumn($column);
            }

            // Extract primary key.
            $primaryKey = $doctrineTable->getPrimaryKey();
            if ($primaryKey !== null) {
                $table->setPrimaryKey($primaryKey->getColumns());
            }

            // Extract foreign keys.
            foreach ($doctrineTable->getForeignKeys() as $doctrineFk) {
                $foreignKey = new ForeignKey(
                    $doctrineFk->getForeignTableName(),
                    $doctrineFk->getLocalColumns(),
                    $doctrineFk->getForeignColumns(),
                    $doctrineFk->getName()
                );

                // Set onDelete and onUpdate if defined.
                if ($doctrineFk->hasOption('onDelete')) {
                    $foreignKey->setOnDelete($doctrineFk->getOption('onDelete'));
                }

                if ($doctrineFk->hasOption('onUpdate')) {
                    $foreignKey->setOnUpdate($doctrineFk->getOption('onUpdate'));
                }

                $table->addForeignKey($foreignKey);
            }

            // Extract indexes (excluding primary key).
            foreach ($doctrineTable->getIndexes() as $doctrineIndex) {
                // Skip primary key index, we already handled it.
                if ($doctrineIndex->isPrimary()) {
                    continue;
                }

                $index = new Index(
                    $doctrineIndex->getName(),
                    $doctrineIndex->getColumns(),
                    $doctrineIndex->isUnique()
                );

                // Add any available flags.
                if (method_exists($doctrineIndex, 'getFlags')) {
                    $index->setFlags($doctrineIndex->getFlags());
                }

                $table->addIndex($index);
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    /**
     * Gets the name of the type of a column of Doctrine DBAL.
     *
     * @param DoctrineColumn $doctrineColumn
     * @return string
     */
    private function getColumnType(DoctrineColumn $doctrineColumn): string
    {
        // Obtener el tipo usando Type::lookupName()
        try {
            return DoctrineType::lookupName($doctrineColumn->getType());
        } catch (\Exception $e) {
            // Fallback: derivar el tipo del nombre de la clase
            $className = get_class($doctrineColumn->getType());
            $shortName = substr($className, strrpos($className, '\\') + 1);
            return strtolower(str_replace('Type', '', $shortName));
        }
    }
}
