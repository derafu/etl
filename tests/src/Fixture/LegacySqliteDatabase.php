<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Fixture;

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Doctrine\DBAL\Connection;

/**
 * A custom adapter exposing the original contract over a real SQLite database.
 * It intentionally does not opt in to pipeline-wide transactions.
 */
final class LegacySqliteDatabase implements DatabaseInterface
{
    private readonly DoctrineDatabase $database;

    public function __construct()
    {
        $this->database = new DoctrineDatabase(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /** {@inheritDoc} */
    public function connection(): Connection
    {
        return $this->database->connection();
    }

    /** {@inheritDoc} */
    public function options(): array
    {
        return $this->database->options();
    }

    /** {@inheritDoc} */
    public function schema(): SchemaInterface
    {
        return $this->database->schema();
    }

    /** {@inheritDoc} */
    public function dump(array $options = []): string
    {
        return $this->database->dump($options);
    }

    /** {@inheritDoc} */
    public function save(string $file, array $options = []): string
    {
        return $this->database->save($file, $options);
    }

    /** {@inheritDoc} */
    public function data(array $options = []): array
    {
        return $this->database->data($options);
    }

    /** {@inheritDoc} */
    public function spreadsheet(array $options = []): SpreadsheetInterface
    {
        return $this->database->spreadsheet($options);
    }

    /** {@inheritDoc} */
    public function sync(DatabaseInterface $source, array $options = []): self
    {
        $this->database->sync($source, $options);
        return $this;
    }

    /** {@inheritDoc} */
    public function load(string|array|SpreadsheetInterface|DatabaseInterface $source, array $options = []): int
    {
        return $this->database->load($source, $options);
    }
}
