<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Pipeline;

use Derafu\ETL\Load\Contract\DataTargetInterface;
use Derafu\ETL\Pipeline\Contract\PipelineResultInterface;

/**
 * PipelineResult is the result of a pipeline execution.
 */
final class PipelineResult implements PipelineResultInterface
{
    public function __construct(
        private readonly DataTargetInterface $target,
        private readonly int $rowsLoaded,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function target(): DataTargetInterface
    {
        return $this->target;
    }

    /**
     * {@inheritDoc}
     */
    public function rowsLoaded(): int
    {
        return $this->rowsLoaded;
    }
}
