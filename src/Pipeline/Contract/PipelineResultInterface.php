<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Pipeline\Contract;

use Derafu\ETL\Load\Contract\DataTargetInterface;

/**
 * PipelineResultInterface is the result of a pipeline execution.
 */
interface PipelineResultInterface
{
    /**
     * Get the target.
     *
     * @return DataTargetInterface The target.
     */
    public function target(): DataTargetInterface;

    /**
     * Get the number of rows loaded.
     *
     * @return int The number of rows loaded.
     */
    public function rowsLoaded(): int;
}
