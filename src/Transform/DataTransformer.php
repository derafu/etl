<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Transform;

use Derafu\ETL\Transform\Contract\DataRulesInterface;
use Derafu\ETL\Transform\Contract\DataTransformerInterface;

/**
 * DataTransformer is a class that contains the logic for the transformation of
 * the data.
 */
final class DataTransformer implements DataTransformerInterface
{
    /**
     * {@inheritDoc}
     */
    public function createRules(array $rules): DataRulesInterface
    {
        return new DataRules($rules);
    }

    /**
     * {@inheritDoc}
     */
    public function transform(array $data, DataRulesInterface $rules): array
    {
        return $rules->transform($data);
    }
}
