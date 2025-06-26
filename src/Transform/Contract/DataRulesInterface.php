<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Transform\Contract;

/**
 * DataRulesInterface is the interface for the DataRules class.
 */
interface DataRulesInterface
{
    /**
     * Transform the data.
     *
     * @param array $data The data to transform.
     * @return array The transformed data.
     */
    public function transform(array $data): array;
}
