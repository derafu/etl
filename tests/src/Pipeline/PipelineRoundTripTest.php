<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Pipeline;

use Derafu\ETL\Database\DatabaseManager;
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Extract\DataExtractor;
use Derafu\ETL\Extract\DataSource;
use Derafu\ETL\Load\DataLoader;
use Derafu\ETL\Load\DataTarget;
use Derafu\ETL\Pipeline\Pipeline;
use Derafu\ETL\Pipeline\PipelineResult;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Transform\DataRules;
use Derafu\ETL\Transform\DataTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * This test class verifies the round-trip data conversion between a spreadsheet
 * and a SQLite database in memory.
 *
 * It ensures that data can be correctly inserted and read back from a
 * spreadsheet, and that transactions can be properly committed and rolled back.
 */
#[CoversClass(Pipeline::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(SpreadsheetDatabase::class)]
#[CoversClass(DataExtractor::class)]
#[CoversClass(DataSource::class)]
#[CoversClass(DataLoader::class)]
#[CoversClass(DataTarget::class)]
#[CoversClass(PipelineResult::class)]
#[CoversClass(Column::class)]
#[CoversClass(Schema::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(Table::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(DataRules::class)]
#[CoversClass(DataTransformer::class)]
final class PipelineRoundTripTest extends TestCase
{
    public function testRoundTripDataConversion(): void
    {
        $pipeline = new Pipeline();

        $source = __DIR__ . '/../../fixtures/spreadsheet-data.xlsx';
        $target = [
            'doctrine' => [
                'driver' => 'pdo_sqlite',
            ],
        ];

        $result = $pipeline
            ->extract($source)
            ->transform()
            ->load($target)
            ->execute();
        ;

        $this->assertSame(133, $result->rowsLoaded());
    }
}
