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
use Derafu\Translation\Exception\Core\TranslatableRuntimeException as RuntimeException;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column as DoctrineColumn;
use Doctrine\DBAL\Schema\Index\IndexType as DoctrineIndexType;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Types\Type as DoctrineType;

/**
 * Extracts schema information from a Doctrine DBAL Schema object.
 */
final class DoctrineSchemaSource implements SchemaSourceInterface
{
    public function __construct(private readonly ?AbstractPlatform $platform = null)
    {
    }

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
            $engine = $this->platformName();
            if ($engine === 'mysql') {
                $table->setPlatformOptions([$engine => [
                    'charset' => $doctrineTable->getOption('charset'),
                    'collation' => $doctrineTable->getOption('collation'),
                ]]);
            }

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

                    $column->setScale($doctrineColumn->getScale());
                }

                if ($engine !== null) {
                    $column->setPlatformOptions([$engine => [
                        'charset' => $doctrineColumn->getCharset(),
                        'collation' => $doctrineColumn->getCollation(),
                    ]]);
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
                    $action = $doctrineFk->getOption('onDelete');
                    if ($action !== null && !is_string($action)) {
                        throw new InvalidArgumentException([
                            'Schema field "{field}" must be a string; {type} given.',
                            'field' => 'onDelete',
                            'type' => get_debug_type($action),
                        ]);
                    }
                    $foreignKey->setOnDelete($action);
                }

                if ($doctrineFk->hasOption('onUpdate')) {
                    $action = $doctrineFk->getOption('onUpdate');
                    if ($action !== null && !is_string($action)) {
                        throw new InvalidArgumentException([
                            'Schema field "{field}" must be a string; {type} given.',
                            'field' => 'onUpdate',
                            'type' => get_debug_type($action),
                        ]);
                    }
                    $foreignKey->setOnUpdate($action);
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
                    match ($doctrineIndex->getType()) {
                        DoctrineIndexType::REGULAR => IndexType::REGULAR,
                        DoctrineIndexType::UNIQUE => IndexType::UNIQUE,
                        DoctrineIndexType::FULLTEXT => IndexType::FULLTEXT,
                        DoctrineIndexType::SPATIAL => IndexType::SPATIAL,
                    },
                    $doctrineIndex->isClustered()
                );

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

    private function platformName(): ?string
    {
        return match (true) {
            $this->platform instanceof AbstractMySQLPlatform => 'mysql',
            $this->platform instanceof PostgreSQLPlatform => 'postgresql',
            $this->platform instanceof SQLitePlatform => 'sqlite',
            default => null,
        };
    }
}
