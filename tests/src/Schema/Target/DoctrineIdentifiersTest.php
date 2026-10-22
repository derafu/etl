<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema\Target;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineSchemaTarget::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Table::class)]
#[UsesClass(Column::class)]
#[UsesClass(Index::class)]
#[UsesClass(ForeignKey::class)]
#[UsesClass(EtlTranslationResourceProvider::class)]
final class DoctrineIdentifiersTest extends TestCase
{
    public function testPrimaryForeignAndIndexColumnsKeepTheirOrderWithDiscontinuousOrNamedKeys(): void
    {
        $parent = new Table('parent_records');
        $parent->addColumn(new Column('id', 'integer'));
        $parent->addColumn(new Column('tenant', 'integer'));
        $parent->setPrimaryKey([4 => 'tenant', 9 => 'id']);
        $child = new Table('child_records');
        $child->addColumn(new Column('parent_id', 'integer'));
        $child->addColumn(new Column('tenant', 'integer'));
        $child->addForeignKey(new ForeignKey('parent_records', [3 => 'tenant', 8 => 'parent_id'], [2 => 'tenant', 7 => 'id'], 'fk_parent'));
        $child->addIndex(new Index('ix_parent', ['first' => 'tenant', 'second' => 'parent_id']));
        $schema = new Schema();
        $schema->addTable($parent);
        $schema->addTable($child);

        $doctrine = (new DoctrineSchemaTarget())->applySchema($schema);
        $parentTable = $doctrine->getTable('parent_records');
        $primaryKey = $parentTable->getPrimaryKeyConstraint();
        $this->assertNotNull($primaryKey);
        $this->assertSame(['tenant', 'id'], array_map(static fn ($name): string => $name->getIdentifier()->getValue(), $primaryKey->getColumnNames()));
        $childTable = $doctrine->getTable('child_records');
        $this->assertSame(['tenant', 'parent_id'], $childTable->getIndex('ix_parent')->getColumns());
        $foreignKeys = $childTable->getForeignKeys();
        $this->assertCount(1, $foreignKeys);
        $foreignKey = reset($foreignKeys);
        $this->assertNotFalse($foreignKey);
        $this->assertSame(['tenant', 'parent_id'], $foreignKey->getLocalColumns());
        $this->assertSame(['tenant', 'id'], $foreignKey->getForeignColumns());

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        foreach ($doctrine->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->executeStatement('INSERT INTO parent_records (tenant, id) VALUES (1, 2)');
        $connection->executeStatement('INSERT INTO child_records (tenant, parent_id) VALUES (1, 2)');
        $this->assertSame([['parent_id' => 2, 'tenant' => 1]], $connection->fetchAllAssociative('SELECT * FROM child_records'));
        $this->assertSame([4 => 'tenant', 9 => 'id'], $parent->getPrimaryKey());
        $this->assertSame(['first' => 'tenant', 'second' => 'parent_id'], $child->getIndexes()['ix_parent']->getColumns());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty index name' => ['index'];
        yield 'empty index column' => ['index column'];
        yield 'empty primary key column' => ['primary key'];
        yield 'empty foreign key column' => ['foreign key'];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testEmptyIdentifiersProduceATranslatableException(string $case): void
    {
        $table = new Table('records');
        $table->addColumn(new Column('id', 'integer'));
        if ($case === 'index') {
            $table->addIndex(new Index('', ['id']));
        } elseif ($case === 'index column') {
            $table->addIndex(new Index('ix', ['']));
        } elseif ($case === 'primary key') {
            $table->setPrimaryKey(['']);
        } else {
            $table->addForeignKey(new ForeignKey('records', [''], ['id'], 'fk'));
        }
        $schema = new Schema();
        $schema->addTable($table);

        try {
            (new DoctrineSchemaTarget())->applySchema($schema);
            $this->fail('An empty identifier must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($case === 'index' ? 'Index name must not be empty.' : 'Schema column names must be non-empty strings.', $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
    }
}
