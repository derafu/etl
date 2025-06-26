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

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Extract\Contract\DataSourceInterface;
use Derafu\ETL\Load\Contract\DataTargetInterface;
use Derafu\ETL\Pipeline\Exception\PipelineException;
use Derafu\ETL\Transform\Contract\DataRulesInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;

/**
 * Interface for ETL pipelines.
 *
 * A pipeline orchestrates the Extract-Transform-Load (ETL) process by
 * connecting data sources, transformations, and targets. It provides a fluent
 * interface for defining and executing ETL workflows.
 */
interface PipelineInterface
{
    /**
     * Sets the source for data extraction.
     *
     * @param DataSourceInterface|DatabaseInterface|SpreadsheetInterface|array|string $source Could be:
     *   - DataSourceInterface: A configured data source object.
     *   - DatabaseInterface: A configured database object.
     *   - SpreadsheetInterface: A configured spreadsheet object.
     *   - array: Configuration options for creating a data source.
     *   - string: The path to a file of a spreadsheet.
     * @return self The pipeline instance for method chaining.
     */
    public function extract(
        DataSourceInterface|DatabaseInterface|SpreadsheetInterface|array|string $source
    ): self;

    /**
     * Adds a transformation stage to the pipeline.
     *
     * If the method is called without rules, the pipeline will use the default
     * rules. The pipeline will not use rules if the method is not called. This
     * is useful when the pipeline is used as a part of a larger process, where
     * the rules are set in a different part of the process or are not needed.
     *
     * @param DataRulesInterface|array $rules Could be:
     *   - DataRulesInterface: A rules object.
     *   - array: A configuration array defining the rules.
     * @return self The pipeline instance for method chaining.
     */
    public function transform(DataRulesInterface|array $rules = []): self;

    /**
     * Sets the target for data loading.
     *
     * @param DataTargetInterface|DatabaseInterface|SpreadsheetInterface|array|string $target Could be:
     *   - DataTargetInterface: A configured target object.
     *   - DatabaseInterface: A configured database object.
     *   - SpreadsheetInterface: A configured spreadsheet object.
     *   - array: Configuration options for creating a target.
     *   - string: The path to a file of a spreadsheet.
     * @return self The pipeline instance for method chaining.
     */
    public function load(
        DataTargetInterface|DatabaseInterface|SpreadsheetInterface|array|string $target
    ): self;

    /**
     * Executes the configured ETL pipeline.
     *
     * @param array $options Runtime options for execution.
     * @return PipelineResultInterface The result of the ETL process.
     * @throws PipelineException If pipeline execution fails.
     */
    public function execute(array $options = []): PipelineResultInterface;

    /**
     * Resets the pipeline to its initial state.
     *
     * @return self The pipeline instance for method chaining.
     */
    public function reset(): self;
}
