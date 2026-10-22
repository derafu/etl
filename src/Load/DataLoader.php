<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Load;

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Database\Contract\DatabaseManagerInterface;
use Derafu\ETL\Database\DatabaseManager;
use Derafu\ETL\Load\Contract\DataLoaderInterface;
use Derafu\ETL\Load\Contract\DataTargetInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;

/**
 * DataLoader is responsible for loading data from a source to a target.
 */
final class DataLoader implements DataLoaderInterface
{
    /**
     * Constructor.
     *
     * @param DatabaseManagerInterface $databaseManager
     */
    public function __construct(
        private readonly DatabaseManagerInterface $databaseManager = new DatabaseManager(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function createTarget(
        DatabaseInterface|SpreadsheetInterface|array|string $target
    ): DataTargetInterface {
        if (!$target instanceof DatabaseInterface) {
            $target = $this->databaseManager->connect($target);
        }

        return new DataTarget($target);
    }

    /**
     * {@inheritDoc}
     */
    public function load(DataTargetInterface $target, array $data): int
    {
        $data = $this->validateData($data);
        $rowsLoaded = 0;

        foreach ($data as $table => $rows) {
            $rowsLoaded += $target->load((string) $table, $rows);
        }

        return $rowsLoaded;
    }

    /**
     * Validate the entire transformed structure before loading the first table.
     * Individual row validation belongs to the target database.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, array<array-key, mixed>>
     */
    private function validateData(array $data): array
    {
        $validated = [];
        foreach ($data as $table => $rows) {
            if ((string) $table === '') {
                throw new InvalidArgumentException('Table names must not be empty.');
            }
            if (!is_array($rows)) {
                throw new InvalidArgumentException([
                    'Rows for table "{table}" must be an array; {type} given.',
                    'table' => (string) $table,
                    'type' => get_debug_type($rows),
                ]);
            }
            $validated[$table] = $rows;
        }

        return $validated;
    }
}
