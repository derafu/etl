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
 * DataTransformerInterface is the interface for the DataTransformer class.
 */
interface DataTransformerInterface
{
    /**
     * Create rules.
     *
     * @param array $rules The rules.
     * @return DataRulesInterface The rules.
     */
    public function createRules(array $rules): DataRulesInterface;

    /**
     * Transform the data.
     *
     * @param array $data The data to transform.
     * @param DataRulesInterface $rules The rules.
     * @return array The transformed data.
     */
    public function transform(array $data, DataRulesInterface $rules): array;
}
