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
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\D2SchemaTarget;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\MarkdownSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\ETL\Schema\Target\SqliteSchemaTarget;
use Derafu\ETL\Schema\Target\TextSchemaTarget;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(D2SchemaTarget::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(MarkdownSchemaTarget::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(SqliteSchemaTarget::class)]
#[CoversClass(TextSchemaTarget::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
final class SchemaPresentationTest extends TestCase
{
    private function schema(): Schema
    {
        $schema = require __DIR__ . '/../../../fixtures/editable-schema.php';
        $this->assertInstanceOf(Schema::class, $schema);
        return $schema;
    }

    public function testEditedModelsAppearInGeneratedDocumentationAndSpreadsheetRoundTrip(): void
    {
        $schema = $this->schema();
        $markdown = (new MarkdownSchemaTarget())->applySchema($schema);
        $text = (new TextSchemaTarget())->applySchema($schema);
        $this->assertStringContainsString('# Schema: Sales', $markdown);
        $this->assertStringContainsString('SCHEMA: Sales', $text);
        foreach (['sales_parent', 'sales_child', 'amount', 'decimal', 'CASCADE', 'CLUSTERED', 'FULLTEXT', 'SPATIAL'] as $part) {
            $this->assertStringContainsString($part, $markdown);
            $this->assertStringContainsString($part, $text);
        }
        $this->assertStringNotContainsString('draft', $markdown);
        $this->assertStringContainsString('# Database Schema', (new MarkdownSchemaTarget())->applySchema(new Schema()));
        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema($schema);
        $restored = (new SpreadsheetSchemaSource())->extractSchema($spreadsheet);
        $this->assertSame('Sales', $restored->getName());
        $child = $restored->getTable('sales_child');
        $this->assertNotNull($child);
        $amount = $child->getColumn('amount');
        $this->assertNotNull($amount);
        $this->assertSame(0, $amount->getDefault());
        $this->assertSame(2, $amount->getScale());
        $this->assertSame(['tenant', 'parent_id'], $child->getPrimaryKey());
        $this->assertTrue($child->hasIndex('ix_fulltext'));
        $index = $child->getIndex('ix_fulltext');
        $this->assertNotNull($index);
        $this->assertSame(['label'], $index->getColumns());
        $this->assertTrue($index->isClustered());
        $this->assertNull($child->getIndex('missing'));
        $this->assertFalse($child->hasIndex('missing'));
        $foreignKeys = $child->getForeignKeys();
        $this->assertCount(1, $foreignKeys);
        $this->assertSame('fk_parent', $foreignKeys[0]->getName());
        $this->assertSame(['tenant', 'id'], $foreignKeys[0]->getForeignColumns());
        $this->assertSame('sales_parent', $foreignKeys[0]->getForeignTableName());
    }

    public function testD2ClustersHierarchyFilteringAndIndexesDescribeTheSelectedSchema(): void
    {
        $schema = $this->schema();
        $clustered = (new D2SchemaTarget(layout: D2SchemaTarget::LAYOUT_CLUSTERED, includeIndexes: true))->applySchema($schema);
        foreach (['cluster_sales: {', 'sales_parent sales_child', 'tenant: integer pk fk', 'FULLTEXT(label)', 'SPATIAL(label)', 'UNIQUE(label)', 'INDEX(label)', 'ON DELETE CASCADE'] as $part) {
            $this->assertStringContainsString($part, $clustered);
        }
        $this->assertStringNotContainsString('cluster_audit: {', $clustered);
        $hierarchical = (new D2SchemaTarget(layout: D2SchemaTarget::LAYOUT_HIERARCHICAL))->applySchema($schema);
        $this->assertStringContainsString('layout: elk.layered', $hierarchical);
        $minimal = (new D2SchemaTarget(layout: D2SchemaTarget::LAYOUT_MINIMAL))->applySchema($schema);
        $this->assertStringContainsString('style.stroke-dash: 5', $minimal);
        $this->assertStringNotContainsString('tooltip:', $minimal);
        $filtered = (new D2SchemaTarget(tableFilter: ['sales_child']))->applySchema($schema);
        $this->assertStringContainsString('sales_child: {', $filtered);
        $this->assertStringNotContainsString('sales_parent: {', $filtered);
        $this->assertStringNotContainsString('sales_child -> sales_parent', $filtered);
        $this->assertStringNotContainsString('# Indexes', (new D2SchemaTarget(detailLevel: D2SchemaTarget::DETAIL_KEYS_ONLY, includeIndexes: true))->applySchema($schema));
    }

    public function testCompositePrimaryKeysAndBinaryColumnsProduceExecutableSqliteSql(): void
    {
        $schema = new Schema('Binary storage');
        $table = new Table('assets');
        $table->addColumn(new Column('tenant', 'integer'));
        $table->addColumn(new Column('id', 'integer'));
        $table->addColumn(new Column('payload', 'binary'));
        $table->setPrimaryKey(['tenant', 'id']);
        $table->addIndex(new Index('redundant_primary', ['id', 'tenant']));
        $table->addIndex(new Index('ix_payload', ['payload']));
        $schema->addTable($table);
        $audit = new Table('audit');
        $audit->addColumn(new Column('message', 'text'));
        $audit->addIndex(new Index('ix_message', ['message']));
        $schema->addTable($audit);
        $sql = (new SqliteSchemaTarget())->applySchema($schema);
        $this->assertStringContainsString('PRIMARY KEY (`tenant`, `id`)', $sql);
        $this->assertStringContainsString('BLOB', $sql);
        $this->assertStringNotContainsString('redundant_primary', $sql);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement($sql);
        $connection->executeStatement("INSERT INTO assets VALUES (1, 2, X'0001FF')");
        $this->assertSame('0001FF', $connection->fetchOne('SELECT hex(payload) FROM assets'));
        $this->assertSame(['tenant', 'id'], $connection->createSchemaManager()->introspectTable('assets')->getPrimaryKey()?->getColumns());
        $this->assertTrue($connection->createSchemaManager()->introspectTable('audit')->hasIndex('ix_message'));
    }

    public function testDoctrineDefaultsAreAppliedByTheRealDatabase(): void
    {
        $table = new Table('settings');
        $table->addColumn((new Column('label', 'string'))->setNullable(false)->setDefault('Unknown'));
        $schema = (new Schema())->addTable($table);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $doctrine = (new DoctrineSchemaTarget())->applySchema($schema);
        foreach ($doctrine->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->executeStatement('INSERT INTO settings DEFAULT VALUES');
        $this->assertSame([['label' => 'Unknown']], $connection->fetchAllAssociative('SELECT * FROM settings'));
    }
}
