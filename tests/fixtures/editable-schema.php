<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Enum\IndexType;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Table;

$schema = (new Schema())->setName('Sales');
$parent = (new Table('draft_parent'))->setName('sales_parent');
$parent->addColumn(new Column('tenant', 'integer'));
$parent->addColumn(new Column('id', 'integer'));
$parent->setPrimaryKey(['tenant', 'id']);
$schema->addTable($parent);

$child = new Table('sales_child');
$child->addColumn(new Column('tenant', 'integer'));
$child->addColumn(new Column('parent_id', 'integer'));
$child->addColumn((new Column('draft', 'string'))->setName('amount')->setType('decimal')->setPrecision(10)->setScale(2)->setNullable(false)->setDefault(0));
$child->addColumn((new Column('label', 'string'))->setLength(80));
$child->setPrimaryKey(['tenant', 'parent_id']);
$foreignKey = (new ForeignKey('draft_parent', ['draft'], ['draft']))
    ->setName('fk_parent')
    ->setForeignTableName('sales_parent')
    ->setLocalColumns(['tenant', 'parent_id'])
    ->setForeignColumns(['tenant', 'id'])
    ->setOnDelete('CASCADE')
    ->setOnUpdate('CASCADE');
$child->addForeignKey($foreignKey);
foreach (IndexType::cases() as $type) {
    $index = (new Index('draft', ['draft'], $type))
        ->setName('ix_' . $type->value)
        ->setColumns(['label'])
        ->setClustered(true);
    $child->addIndex($index);
}
$schema->addTable($child);
$schema->addTable((new Table('audit'))->addColumn(new Column('message', 'text')));
$schema->addTable((new Table('other_record'))->addColumn(new Column('value', 'integer')));

return $schema;
