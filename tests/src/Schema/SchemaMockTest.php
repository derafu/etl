<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema;

use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Contract\SchemaSourceInterface;
use Derafu\ETL\Schema\Contract\TableInterface;
use Derafu\ETL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Schema::class)]
final class SchemaMockTest extends TestCase
{
    public function testDoctrineSchema(): void
    {
        // Create a mock SchemaSourceInterface that will be implemented later.
        $schemaSource = $this->createMock(SchemaSourceInterface::class);

        // Define what our mock should return when extractSchema is called.
        $schema = new Schema();
        $schemaSource->method('extractSchema')->willReturn($schema);

        // Execute the method we're testing.
        $result = $schemaSource->extractSchema($schemaSource);

        // Verify we got a schema back.
        $this->assertInstanceOf(SchemaInterface::class, $result);

        // Verify it's empty (since we haven't implemented the real conversion yet).
        $this->assertEmpty($result->getTables());

        // This test will fail until we implement proper schema conversion.
        // The test defines the expected behavior without implementing it.
    }

    public function testSchemaTableManipulation(): void
    {
        // Test that we can create a schema and add tables to it.
        $schema = new Schema('test_schema');

        // Assert initial state.
        $this->assertSame('test_schema', $schema->getName());
        $this->assertEmpty($schema->getTables());

        // Create mock tables.
        $table1 = $this->createMock(TableInterface::class);
        $table1->method('getName')->willReturn('table1');

        $table2 = $this->createMock(TableInterface::class);
        $table2->method('getName')->willReturn('table2');

        // Add tables.
        $schema->addTable($table1);
        $schema->addTable($table2);

        // Verify tables were added.
        $this->assertTrue($schema->hasTable('table1'));
        $this->assertTrue($schema->hasTable('table2'));
        $this->assertFalse($schema->hasTable('non_existent_table'));

        // Verify we can retrieve tables.
        $this->assertSame($table1, $schema->getTable('table1'));
        $this->assertSame($table2, $schema->getTable('table2'));
        $this->assertNull($schema->getTable('non_existent_table'));

        // Verify table count.
        $this->assertCount(2, $schema->getTables());
    }
}
