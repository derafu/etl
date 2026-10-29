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
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Import the production YAML and consume its tagged translation resources.
 */
#[CoversNothing]
final class ServicesTest extends TestCase
{
    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator([
            dirname(__DIR__, 2) . '/resources/config',
            (InstalledVersions::getInstallPath('derafu/spreadsheet')
                ?? throw new RuntimeException('derafu/spreadsheet must be installed for these tests.')) . '/resources/config',
        ])))
            ->load('etl-services.yaml');

        return $container;
    }

    public function testTheConfiguredProviderCanBeConstructed(): void
    {
        $container = $this->load();
        $container->getDefinition(EtlTranslationResourceProvider::class)->setPublic(true);
        $container->compile();
        $provider = $container->get(EtlTranslationResourceProvider::class);
        $this->assertInstanceOf(EtlTranslationResourceProvider::class, $provider);
        $directories = [...$provider->getDirectories()];
        $this->assertCount(1, $directories);
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/resources/translations'), realpath($directories[0]));
    }

    public function testTaggedResourcesTranslateARealSchemaValidationException(): void
    {
        $container = $this->load();
        $this->assertArrayHasKey(
            EtlTranslationResourceProvider::class,
            $container->findTaggedServiceIds('derafu_translation.resource_provider')
        );
        $container->register('app.translator', Translator::class)
            ->setFactory([TranslatorFactory::class, 'create'])
            ->setArguments(['es', ['en'], new TaggedIteratorArgument('derafu_translation.resource_provider')])
            ->setPublic(true);
        $container->compile();
        $translator = $container->get('app.translator');
        $this->assertInstanceOf(Translator::class, $translator);

        try {
            (new Table('products'))->setPlatformOptions(['mysql' => ['unknown' => 'utf8mb4']]);
            $this->fail('An unsupported platform text option was accepted.');
        } catch (TranslatableInvalidArgumentException $exception) {
            $this->assertSame('Unsupported platform text option "unknown".', $exception->getMessage());
            $this->assertSame('Opción de texto de plataforma no admitida: "unknown".', $exception->trans($translator));
        }
    }
}
