<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL;

use Composer\InstalledVersions;
use Derafu\ETL\Command\PipelineCommand;
use Derafu\ETL\Database\Contract\DatabaseManagerInterface;
use Derafu\ETL\Pipeline\Contract\PipelineInterface;
use Derafu\Spreadsheet\Contract\SpreadsheetFactoryInterface;
use Derafu\TestsETL\Fixture\RecordingCaster;
use Derafu\TestsETL\Fixture\TemporaryFiles;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Exercise the production service composition with real files and SQLite.
 */
#[CoversNamespace('Derafu\ETL')]
final class ServiceCompositionTest extends TestCase
{
    use TemporaryFiles;

    private function container(string $config = 'services.yaml'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $locator = new FileLocator([
            __DIR__ . '/../fixtures/config',
            (InstalledVersions::getInstallPath('derafu/spreadsheet')
                ?? throw new RuntimeException('derafu/spreadsheet must be installed for these tests.')) . '/resources/config',
        ]);
        (new YamlFileLoader($container, $locator))->load($config);
        $container->compile();

        return $container;
    }

    private function manager(ContainerBuilder $container): DatabaseManagerInterface
    {
        $manager = $container->get('test.manager');
        $this->assertInstanceOf(DatabaseManagerInterface::class, $manager);

        return $manager;
    }

    private function pipeline(ContainerBuilder $container): PipelineInterface
    {
        $pipeline = $container->get('test.pipeline');
        $this->assertInstanceOf(PipelineInterface::class, $pipeline);

        return $pipeline;
    }

    private function source(ContainerBuilder $container, string $name = ' Laptop '): string
    {
        $factory = $container->get('test.factory');
        $this->assertInstanceOf(SpreadsheetFactoryInterface::class, $factory);
        $document = $factory->create();
        $document->createSheet('products', [['id' => 'P001', 'name' => $name]], true);
        $source = $this->directory . '/' . bin2hex(random_bytes(4)) . '.xlsx';
        $this->manager($container)->connect($document)->save($source);

        return $source;
    }

    public function testTransferRulesAndExportPreserveRowsAndSchema(): void
    {
        $container = $this->container();
        $manager = $this->manager($container);
        $source = $this->source($container);
        $target = $this->directory . '/products.sqlite';
        $rules = require __DIR__ . '/../fixtures/rules/callable-product-names.php';
        $result = $this->pipeline($container)->extract($source)->transform($rules)->load(['file' => $target, 'format' => 'sqlite'])->execute();
        $this->assertSame(1, $result->rowsLoaded());
        $database = $result->target()->database();
        $this->assertSame([['id' => 'P001', 'name' => 'Laptop']], $database->data(['table' => 'products']));

        $export = $this->directory . '/export.xlsx';
        $manager->connect($database->spreadsheet())->save($export);
        $reopened = $manager->connect($export);
        $this->assertSame($database->data(), $reopened->data());
        $this->assertNotNull($reopened->spreadsheet()->getSheet('__schema'));
        $this->assertSame(['id'], $reopened->schema()->getTable('products')?->getPrimaryKey());
    }

    public function testSeparatelyConfiguredPipelinesKeepTheirOwnSourceAndTarget(): void
    {
        $container = $this->container();
        $first = $this->pipeline($container);
        $second = $this->pipeline($container);
        $this->assertNotSame($first, $second);
        $first->extract($this->source($container, 'First'))->transform()->load(['file' => $this->directory . '/first.sqlite', 'format' => 'sqlite']);
        $second->extract($this->source($container, 'Second'))->transform()->load(['file' => $this->directory . '/second.sqlite', 'format' => 'sqlite']);
        $secondResult = $second->execute();
        $firstResult = $first->execute();
        $this->assertSame([['id' => 'P001', 'name' => 'First']], $firstResult->target()->database()->data(['table' => 'products']));
        $this->assertSame([['id' => 'P001', 'name' => 'Second']], $secondResult->target()->database()->data(['table' => 'products']));
    }

    public function testCommandUsesTheConfiguredPipelineAndRulesFile(): void
    {
        $container = $this->container();
        $command = $container->get('test.command');
        $this->assertInstanceOf(PipelineCommand::class, $command);
        $tester = new CommandTester($command);
        $target = $this->directory . '/command.sqlite';
        $status = $tester->execute([
            'source' => $this->source($container),
            'target' => $target,
            '--rules' => __DIR__ . '/../fixtures/rules/callable-product-names.php',
            '--no-confirm' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertSame([['id' => 'P001', 'name' => 'Laptop']], $this->manager($container)->connect(['file' => $target, 'format' => 'sqlite'])->data(['table' => 'products']));
    }

    public function testCasterReplacementReachesMetadataAndAdapterIoWithoutCastingBusinessCells(): void
    {
        $container = $this->container('custom-caster.yaml');
        $manager = $this->manager($container);
        $caster = $container->get(RecordingCaster::class);
        $this->assertInstanceOf(RecordingCaster::class, $caster);
        $file = $this->directory . '/created.xlsx';
        $database = $manager->connect($file, ['createIfNotExists' => true]);
        $this->assertSame(1, $caster->writeCalls);
        $database->load(['products' => [['id' => 'P001', 'name' => 'Laptop']]]);
        $database->save($file);
        $this->assertSame(2, $caster->writeCalls);

        $reopened = $manager->connect($file);
        $this->assertSame(1, $caster->readCalls);
        $this->assertSame([['id' => 'P001', 'name' => 'stored:Laptop']], $reopened->data(['table' => 'products']));
        $this->assertSame(['id'], $reopened->schema()->getTable('products')?->getPrimaryKey());

        $dump = $database->dump(['format' => 'xlsx']);
        $this->assertSame(3, $caster->writeCalls);
        $destination = $manager->connect(['file' => $this->directory . '/destination.xlsx', 'createIfNotExists' => true]);
        $this->assertSame(4, $caster->writeCalls);
        $destination->load($dump, ['format' => 'xlsx']);
        $this->assertSame(2, $caster->readCalls);
        $this->assertSame([['id' => 'P001', 'name' => 'Laptop']], $destination->data(['table' => 'products']));
    }
}
