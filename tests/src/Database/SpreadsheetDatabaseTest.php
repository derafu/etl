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
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\ForeignKey;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\Spreadsheet\SpreadsheetLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpreadsheetDatabase::class)]
#[CoversClass(AbstractDatabase::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Index::class)]
final class SpreadsheetDatabaseTest extends TestCase
{
    private function createDatabase(): SpreadsheetDatabase
    {
        $spreadsheet = (new SpreadsheetLoader())->loadFromFile(
            __DIR__ . '/../../fixtures/spreadsheet-data.xlsx'
        );

        return new SpreadsheetDatabase($spreadsheet);
    }

    public function testSchemaIsCachedBetweenCalls(): void
    {
        $database = $this->createDatabase();

        $this->assertSame($database->schema(), $database->schema());
    }

    public function testSchemaIsRecomputedAfterLoad(): void
    {
        $database = $this->createDatabase();
        $before = $database->schema();

        $database->load($this->createDatabase());

        $after = $database->schema();
        $this->assertNotSame($before, $after);
        $this->assertSame($after, $database->schema());
    }
}
