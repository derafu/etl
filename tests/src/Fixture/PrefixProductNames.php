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

use Derafu\ETL\Transform\Contract\DataRulesInterface;

final class PrefixProductNames implements DataRulesInterface
{
    /** {@inheritDoc} */
    public function transform(array $data): array
    {
        foreach ($data as $table => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $key => $row) {
                if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                    $row['name'] = 'Product: ' . $row['name'];
                    $rows[$key] = $row;
                }
            }
            $data[$table] = $rows;
        }

        return $data;
    }
}
