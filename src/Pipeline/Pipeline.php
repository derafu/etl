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

use Derafu\ETL\Database\Contract\DatabaseInterface;
use Derafu\ETL\Extract\Contract\DataExtractorInterface;
use Derafu\ETL\Extract\Contract\DataSourceInterface;
use Derafu\ETL\Extract\DataExtractor;
use Derafu\ETL\Load\Contract\DataLoaderInterface;
use Derafu\ETL\Load\Contract\DataTargetInterface;
use Derafu\ETL\Load\DataLoader;
use Derafu\ETL\Pipeline\Contract\PipelineInterface;
use Derafu\ETL\Pipeline\Contract\PipelineResultInterface;
use Derafu\ETL\Pipeline\Exception\PipelineException;
use Derafu\ETL\Transform\Contract\DataRulesInterface;
use Derafu\ETL\Transform\Contract\DataTransformerInterface;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\Spreadsheet\Contract\SpreadsheetInterface;

/**
 * Pipeline is responsible for orchestrating the Extract-Transform-Load process.
 *
 * This class provides a fluent interface for defining and executing ETL
 * pipelines. It connects data sources, transformations, and targets to form a
 * complete data processing workflow.
 */
final class Pipeline implements PipelineInterface
{
    /**
     * The source for the extraction step.
     *
     * @var DataSourceInterface
     */
    private DataSourceInterface $source;

    /**
     * The rules for the transformation step.
     *
     * @var DataRulesInterface
     */
    private DataRulesInterface $rules;

    /**
     * The target for the load step.
     *
     * @var DataTargetInterface
     */
    private DataTargetInterface $target;

    /**
     * Constructor.
     *
     * @param DataExtractorInterface $extractor The data extractor.
     * @param DataTransformerInterface $transformer The data transformer.
     * @param DataLoaderInterface $loader The data loader.
     */
    public function __construct(
        private readonly DataExtractorInterface $extractor = new DataExtractor(),
        private readonly DataTransformerInterface $transformer = new DataTransformer(),
        private readonly DataLoaderInterface $loader = new DataLoader(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function extract(
        DataSourceInterface|DatabaseInterface|SpreadsheetInterface|array|string $source
    ): self {
        if (!$source instanceof DataSourceInterface) {
            $source = $this->extractor->createSource($source);
        }

        $this->source = $source;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function transform(DataRulesInterface|array $rules = []): self
    {
        if (is_array($rules)) {
            $rules = $this->transformer->createRules($rules);
        }

        $this->rules = $rules;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function load(
        DataTargetInterface|DatabaseInterface|SpreadsheetInterface|array|string $target
    ): self {
        if (!$target instanceof DataTargetInterface) {
            $target = $this->loader->createTarget($target);
        }

        $this->target = $target;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(array $options = []): PipelineResultInterface
    {
        // Check if the pipeline is valid for execution.
        $this->validate();

        // Extract data from the source.
        $data = $this->extractor->extract($this->source);

        // Transform data if rules are set.
        if (isset($this->rules)) {
            $data = $this->transformer->transform($data, $this->rules);
        }

        // Sync the target with the source.
        if ($options['sync'] ?? true) {
            $this->target->sync($this->source, $options);
        }

        // Load the data from the source into the target.
        $rowsLoaded = $this->loader->load($this->target, $data);

        // Return the result.
        return new PipelineResult($this->target, $rowsLoaded);
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): self
    {
        unset($this->source);
        unset($this->rules);
        unset($this->target);

        return $this;
    }

    /**
     * Validates the ETL pipeline.
     *
     * @throws PipelineException If the ETL pipeline is not valid for execution.
     */
    private function validate(): void
    {
        if (!isset($this->source)) {
            throw new PipelineException(
                'Source is not set in the ETL pipeline. ' .
                'Use the extract() method to set the source.'
            );
        }

        if (!isset($this->rules)) {
            throw new PipelineException(
                'Rules are not set in the ETL pipeline. ' .
                'Use the transform() method to set the rules.'
            );
        }

        if (!isset($this->target)) {
            throw new PipelineException(
                'Target is not set in the ETL pipeline. ' .
                'Use the load() method to set the target.'
            );
        }
    }
}
