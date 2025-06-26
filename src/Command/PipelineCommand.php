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
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
 * Example usage with the behavior described above:
 *
 *   php app/console.php derafu:etl data.xlsx data.sqlite
 *   php app/console.php derafu:etl data.xlsx --drop-tables
 *   php app/console.php derafu:etl data.xlsx --structure-only
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
                $this->formatValue($value),
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
     * @return array The source options.
     */
    private function getSource(InputInterface $input): array
    {
        $file = $input->getArgument('source');

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
     * @return array The target options.
     */
    private function getTarget(InputInterface $input): array
    {
        $file = $input->getArgument('target');
        if (empty($file)) {
            $file = pathinfo($input->getArgument('source'), PATHINFO_FILENAME) . '.sqlite';
        }

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
     * @return array The transformer.
     */
    private function getTransformer(InputInterface $input): array
    {
        $options = [];

        // TODO: Define the transformer options.

        return $options;
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
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
