<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Command;

use Derafu\ETL\Pipeline\Contract\PipelineInterface;
use Derafu\ETL\Pipeline\Pipeline;
use Derafu\ETL\Transform\Contract\DataRulesInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Exception;
use JsonException;
use Stringable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Command to extract, transform and load data from a source to a target.
 *
 * For example, this command can take a spreadsheet file (XLSX, ODS, CSV, etc.)
 * and convert it to a SQLite database. In this case the spreadsheet must have a
 * specific structure:
 *
 *   - A sheet named "__schema" containing the database schema information.
 *     Actually, this sheet is optional, but very useful to define the database
 *     schema before loading the data. Otherwise, the schema will be extracted
 *     from the data and the results may not be what you expect if not enough
 *     data is available in each sheet.
 *   - Additional sheets containing the data for each table.
 *
 * The optional --rules PHP file returns an ordered array of DataRulesInterface
 * instances or callables accepting and returning the table data array. Rules
 * transform values; schema synchronization still uses the source schema.
 *
 * Example usage with the behavior described above:
 *
 *   php app/console.php derafu:etl data.xlsx data.sqlite
 *   php app/console.php derafu:etl data.xlsx data.sqlite --rules=rules.php
 *   php app/console.php derafu:etl data.xlsx --drop-tables
 *   php app/console.php derafu:etl data.xlsx --structure-only
 *
 * The text of the command (its description, the help of its arguments and
 * options, and the output) is in English, like the one of the commands of
 * Symfony: it is a technical tool for whoever administers the application, and
 * it is not translated.
 */
#[AsCommand(
    name: 'derafu:etl',
    description: 'Extract, Transform and Load Data from a Source to a Target.'
)]
final class PipelineCommand extends Command
{
    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source spreadsheet file.'
            )
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'Target database (default: source name with .sqlite extension).'
            )
            ->addOption(
                'rules',
                null,
                InputOption::VALUE_REQUIRED,
                'PHP file returning an ordered array of transformation rules.'
            )
            ->addOption(
                'no-confirm',
                null,
                InputOption::VALUE_NONE,
                'Do not confirm the ETL pipeline process.'
            )
        ;
    }

    /**
     * Constructor.
     *
     * @param PipelineInterface $pipeline The ETL pipeline.
     * @param string|null $name The command name.
     */
    public function __construct(
        private readonly PipelineInterface $pipeline = new Pipeline(),
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     * @return int The command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get source and target options.
        $source = $this->getSource($input);
        $target = $this->getTarget($input);
        $transformer = $this->getTransformer($input);

        // Show configuration.
        $io->section('Configuration of the ETL pipeline process');
        $headers = ['Step', 'Option', 'Type', 'Value'];
        $rows = [];
        foreach ($source as $key => $value) {
            $rows[] = [
                'Extract',
                $key,
                get_debug_type($value),
                $this->formatValue($value),
            ];
        }
        foreach ($transformer as $key => $value) {
            $rows[] = [
                'Transform',
                $key,
                get_debug_type($value),
                is_string($value) ? $value : get_debug_type($value),
            ];
        }
        foreach ($target as $key => $value) {
            $rows[] = [
                'Load',
                $key,
                get_debug_type($value),
                $this->formatValue($value),
            ];
        }
        $io->table($headers, $rows);

        // Confirm the ETL pipeline process.
        $confirm = $input->getOption('no-confirm') ?? false;
        if (!$confirm) {
            $io->section('Confirm the ETL pipeline process');
            $io->text('Are you sure you want to proceed?');
            if (!$io->confirm('Continue?', false)) {
                $io->error('ETL pipeline process aborted.');
                return Command::FAILURE;
            }
        }

        // Execute the ETL pipeline process.
        try {
            $this->pipeline
                ->extract($source)
                ->transform($transformer)
                ->load($target)
                ->execute()
            ;
        } catch (Exception $e) {
            $io->error(sprintf(
                'Error during the ETL pipeline process: %s',
                $e->getMessage()
            ));
            return Command::FAILURE;
        }

        // Return success.
        $io->success('ETL pipeline process completed successfully.');
        return Command::SUCCESS;
    }

    /**
     * Get the source database options.
     *
     * @param InputInterface $input The input interface.
     * @return array<array-key, mixed> The source options.
     */
    private function getSource(InputInterface $input): array
    {
        $file = $this->requirePath($input->getArgument('source'), 'source');

        $options = [
            'file' => $file,
            'format' => pathinfo($file, PATHINFO_EXTENSION),
            'readOnly' => true,
            'createIfNotExists' => false,
        ];

        return $options;
    }

    /**
     * Get the target database options.
     *
     * @param InputInterface $input The input interface.
     * @return array<array-key, mixed> The target options.
     */
    private function getTarget(InputInterface $input): array
    {
        $file = $input->getArgument('target');
        if (empty($file)) {
            $file = pathinfo($this->requirePath($input->getArgument('source'), 'source'), PATHINFO_FILENAME) . '.sqlite';
        }

        $file = $this->requirePath($file, 'target');

        $options = [
            'file' => $file,
            'format' => pathinfo($file, PATHINFO_EXTENSION),
            'readOnly' => false,
            'createIfNotExists' => true,
        ];

        return $options;
    }

    /**
     * Get the transformer for manipulating the source data.
     *
     * @param InputInterface $input The input interface.
     * @return array<array-key, DataRulesInterface|callable> The ordered transformation rules.
     */
    private function getTransformer(InputInterface $input): array
    {
        $file = $input->getOption('rules');
        if ($file === null) {
            return [];
        }
        $file = $this->requirePath($file, 'rules');
        $path = realpath($file);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException([
                'Rules file must exist and be readable: {file}',
                'file' => $file,
            ]);
        }
        try {
            $rules = (static fn (string $path): mixed => require $path)($path);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException([
                'Could not load rules file "{file}": {error}.',
                'file' => $file,
                'error' => $exception->getMessage(),
            ], $exception->getCode(), $exception);
        }
        if (!is_array($rules)) {
            throw new InvalidArgumentException([
                'Rules file must return an array; {type} given.',
                'type' => get_debug_type($rules),
            ]);
        }
        $validated = [];
        foreach ($rules as $key => $rule) {
            if (!$rule instanceof DataRulesInterface && !is_callable($rule)) {
                throw new InvalidArgumentException([
                    'Rule "{rule}" must implement DataRulesInterface or be callable; {type} given.',
                    'rule' => $key,
                    'type' => get_debug_type($rule),
                ]);
            }
            $validated[$key] = $rule;
        }

        return $validated;
    }

    /**
     * Format the value.
     *
     * @param mixed $value The value to format.
     * @return string The formatted value.
     */
    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException([
                    'Could not encode option value as JSON: {error}.',
                    'error' => $exception->getMessage(),
                ], $exception->getCode(), $exception);
            }
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException([
            'Unsupported option value type: {type}.',
            'type' => get_debug_type($value),
        ]);
    }

    private function requirePath(mixed $value, string $argument): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException([
                'Argument "{argument}" must be a string; {type} given.',
                'argument' => $argument,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }
}
