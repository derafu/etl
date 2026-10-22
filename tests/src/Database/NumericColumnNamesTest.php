<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Database;

use Derafu\ETL\Database\Abstract\AbstractDatabase;
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Contract\ColumnInterface;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\Spreadsheet\Spreadsheet;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(AbstractDatabase::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[UsesClass(SpreadsheetDatabase::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Table::class)]
#[UsesClass(Column::class)]
final class NumericColumnNamesTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function sheetLayouts(): iterable
    {
        yield 'associative keys' => [true];
        yield 'indexed headers' => [false];
    }

    #[DataProvider('sheetLayouts')]
    public function testNumericColumnNamesBecomeStringsWithoutChangingTheirValues(bool $associative): void
    {
        $source = new Spreadsheet();
        $source->createSheet('sales', $associative ? [
            ['id' => 1, 2024 => 100, '2025' => 200, '01' => 300],
        ] : [
            ['id', 2024, '2025', '01'],
            [1, 100, 200, 300],
        ], $associative);
        $database = new SpreadsheetDatabase($source);
        $before = $database->data(['table' => 'sales']);

        $table = $database->schema()->getTable('sales');

        $this->assertNotNull($table);
        $this->assertSame(['id', '2024', '2025', '01'], array_values(array_map(
            static fn (ColumnInterface $column): string => $column->getName(),
            $table->getColumns()
        )));
        foreach (['2024', '2025', '01'] as $name) {
            $column = $table->getColumn($name);
            $this->assertNotNull($column);
            $this->assertSame($name, $column->getName());
        }
        $this->assertSame(['id'], $table->getPrimaryKey());
        $this->assertSame([['id' => 1, 2024 => 100, 2025 => 200, '01' => 300]], $before);
        $this->assertSame($before, $database->data(['table' => 'sales']));
    }

    public function testSqliteImportsAndReplacesRowsWithQuotedColumnNames(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE "sales" ("id" INTEGER PRIMARY KEY, "2024" INTEGER, "2025" INTEGER, "01" INTEGER, "select" TEXT, "gross total" INTEGER, "customer""tag" TEXT)');
        $target = new DoctrineDatabase($connection);
        $rows = [
            ['id' => 1, 2024 => 100, 2025 => 200, '01' => 300, 'select' => 'Laptop', 'gross total' => 600, 'customer"tag' => "O'Reilly"],
            ['id' => 2, 2024 => 10, 2025 => 20, '01' => 30, 'select' => 'Desktop', 'gross total' => 60, 'customer"tag' => 'Regular'],
        ];
        $source = new Spreadsheet();
        $source->createSheet('sales', $rows, true);

        $this->assertSame(2, $target->load($source));
        $this->assertSame($rows, $connection->fetchAllAssociative('SELECT * FROM "sales" ORDER BY "id"'));

        $replacement = $rows[0];
        $replacement[2024] = 150;
        $replacement['gross total'] = 650;
        $updated = new Spreadsheet();
        $updated->createSheet('sales', [$replacement], true);

        $this->assertSame(1, $target->load($updated));
        $this->assertSame([$replacement, $rows[1]], $connection->fetchAllAssociative('SELECT * FROM "sales" ORDER BY "id"'));
        $this->assertSame($rows, $source->getActiveSheet()->getRows());
        $this->assertSame([$replacement], $updated->getActiveSheet()->getRows());
    }
}
