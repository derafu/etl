<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Pipeline;

use Derafu\ETL\Command\PipelineCommand;
use Derafu\ETL\Database\DatabaseManager;
use Derafu\ETL\Extract\DataExtractor;
use Derafu\ETL\Load\DataLoader;
use Derafu\ETL\Pipeline\Pipeline;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PipelineCommand::class)]
#[UsesClass(Pipeline::class)]
#[UsesClass(DataExtractor::class)]
#[UsesClass(DataLoader::class)]
#[UsesClass(DataTransformer::class)]
#[UsesClass(DatabaseManager::class)]
final class CommandValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPaths(): iterable
    {
        yield 'source' => [['source' => ['invalid']], 'source'];
        yield 'target' => [['source' => 'products.xlsx', 'target' => ['invalid']], 'target'];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidPaths')]
    public function testFileArgumentsRequireStrings(array $arguments, string $argument): void
    {
        $tester = new CommandTester(new PipelineCommand());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Argument "' . $argument . '" must be a string; array given.');
        $tester->execute($arguments, ['interactive' => false]);
    }

    public function testAnOmittedTargetStillDefaultsToSqliteBeforeConfirmation(): void
    {
        $tester = new CommandTester(new PipelineCommand());

        $status = $tester->execute(['source' => 'products.xlsx'], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('products.sqlite', $tester->getDisplay());
        $this->assertStringContainsString('ETL pipeline process aborted.', $tester->getDisplay());
    }
}
