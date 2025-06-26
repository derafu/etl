<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema\Target;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\SqliteSchemaTarget;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteSchemaTarget::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
final class SqliteSchemaTargetTest extends TestCase
{
    public function testApplySchema(): void
    {
        // Load the Doctrine DBAL schema
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Create the SQLite schema target.
        $schemaTarget = new SqliteSchemaTarget();

        // Apply the schema to get the SQLite SQL statements.
        $sqlOutput = $schemaTarget->applySchema($schema);

        // Verify the output is a non-empty string.
        $this->assertIsString($sqlOutput);
        $this->assertNotEmpty($sqlOutput);

        // Verify SQL structure
        $this->assertStringContainsString(
            "BEGIN TRANSACTION;",
            $sqlOutput,
            "Output should begin a transaction."
        );
        $this->assertStringContainsString(
            "COMMIT;",
            $sqlOutput,
            "Output should commit the transaction."
        );

        // Verify drop statements are included by default.
        $this->assertStringContainsString(
            "DROP TABLE IF EXISTS",
            $sqlOutput,
            "Output should include drop statements."
        );

        // Verify that all tables are included
        $expectedTables = [
            'party', 'item', 'tax_scheme', 'tax_category',
            'invoice', 'invoice_line', 'invoice_line_tax',
        ];

        foreach ($expectedTables as $tableName) {
            $this->assertStringContainsString(
                "CREATE TABLE `{$tableName}`",
                $sqlOutput,
                "Output should include CREATE TABLE for '{$tableName}'."
            );
        }

        // Verify column types are correctly mapped to SQLite types
        $this->assertStringContainsString(
            "INTEGER",
            $sqlOutput,
            "Output should map integer types."
        );
        $this->assertStringContainsString(
            "TEXT",
            $sqlOutput,
            "Output should map string types to TEXT."
        );
        $this->assertStringContainsString(
            "REAL",
            $sqlOutput,
            "Output should map decimal types to REAL."
        );

        // Verify constraints are included.
        $this->assertStringContainsString(
            "PRIMARY KEY",
            $sqlOutput,
            "Output should include PRIMARY KEY constraints."
        );
        $this->assertStringContainsString(
            "FOREIGN KEY",
            $sqlOutput,
            "Output should include FOREIGN KEY constraints."
        );
        $this->assertStringContainsString(
            "NOT NULL",
            $sqlOutput,
            "Output should include NOT NULL constraints."
        );

        // Verify indexes are created.
        $this->assertStringContainsString(
            "CREATE INDEX",
            $sqlOutput,
            "Output should include CREATE INDEX statements."
        );
        $this->assertStringContainsString(
            "CREATE UNIQUE INDEX",
            $sqlOutput,
            "Output should include CREATE UNIQUE INDEX statements."
        );

        // Save the SQL to a file for inspection (optional)
        // file_put_contents('schema.sqlite.sql', $sqlOutput);

        // Uncomment to see the output during test execution
        // echo $sqlOutput;
    }

    public function testWithoutDropStatements(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Create the SQLite schema target without drop statements.
        $schemaTarget = new SqliteSchemaTarget(false);

        // Apply the schema to get the SQLite SQL statements.
        $sqlOutput = $schemaTarget->applySchema($schema);

        // Verify drop statements are NOT included.
        $this->assertStringNotContainsString(
            "DROP TABLE IF EXISTS",
            $sqlOutput,
            "Output should not include drop statements."
        );

        // Verify that create statements are still included.
        $this->assertStringContainsString(
            "CREATE TABLE",
            $sqlOutput,
            "Output should include CREATE TABLE statements."
        );
    }

    public function testWithoutFormatting(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Create the SQLite schema target without formatting.
        $schemaTarget = new SqliteSchemaTarget(true, false);

        // Apply the schema to get the SQLite SQL statements.
        $sqlOutput = $schemaTarget->applySchema($schema);

        // Verify the output is a non-empty string.
        $this->assertIsString($sqlOutput);
        $this->assertNotEmpty($sqlOutput);

        // Verify that formatting is different.
        $withFormatting = (new SqliteSchemaTarget(true, true))->applySchema($schema);
        $this->assertNotSame(
            $sqlOutput,
            $withFormatting,
            "Output without formatting should be different from formatted output."
        );
    }

    public function testCreateInMemoryDatabase(): void
    {
        // Load the Doctrine DBAL schema.
        $doctrineSchema = require __DIR__ . '/../../../fixtures/doctrine-dbal-schema.php';

        // Create a schema from the Doctrine DBAL schema.
        $schemaSource = new DoctrineSchemaSource();
        $schema = $schemaSource->extractSchema($doctrineSchema);

        // Create the SQLite schema target.
        $schemaTarget = new SqliteSchemaTarget();

        // Apply the schema to get the SQLite SQL statements.
        $sqlOutput = $schemaTarget->applySchema($schema);

        // Create an in-memory SQLite database.
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        try {
            // Enable foreign keys.
            $pdo->exec('PRAGMA foreign_keys = ON;');

            // Execute the generated SQL.
            $pdo->exec($sqlOutput);

            // If we've made it here, the SQL is valid.
            $this->assertTrue(true, "Generated SQL executed successfully.");

            // Verify that tables were created.
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // sqlite_master and sqlite_sequence are system tables.
            $userTables = array_filter($tables, fn ($table) => !in_array($table, ['sqlite_master', 'sqlite_sequence']));

            $this->assertNotEmpty($userTables, "User tables should be created.");

            // Verify number of tables (excluding system tables).
            $expectedTables = [
                'party', 'item', 'tax_scheme', 'tax_category',
                'invoice', 'invoice_line', 'invoice_line_tax',
            ];

            $this->assertCount(
                count($expectedTables),
                $userTables,
                "Expected number of tables should be created."
            );

            // Verify some basic queries work.
            foreach ($userTables as $table) {
                $stmt = $pdo->query("SELECT * FROM `$table` LIMIT 0");
                $this->assertNotFalse($stmt, "Should be able to query table '$table'.");

                // Get column information.
                $columnCount = $stmt->columnCount();
                $this->assertGreaterThan(0, $columnCount, "Table '$table' should have columns.");
            }

        } catch (PDOException $e) {
            $this->fail("Generated SQL failed to execute: " . $e->getMessage());
        }
    }
}
