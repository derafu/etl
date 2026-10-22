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
use Derafu\ETL\Database\Abstract\AbstractDatabase;
use Derafu\ETL\Database\DatabaseManager;
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Database\SpreadsheetDatabase;
use Derafu\ETL\Extract\DataExtractor;
use Derafu\ETL\Extract\DataSource;
use Derafu\ETL\Load\DataLoader;
use Derafu\ETL\Load\DataTarget;
use Derafu\ETL\Pipeline\Exception\PipelineException;
use Derafu\ETL\Pipeline\Pipeline;
use Derafu\ETL\Pipeline\PipelineResult;
use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Transform\DataRules;
use Derafu\ETL\Transform\DataTransformer;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Spreadsheet\Spreadsheet;
use Derafu\TestsETL\Fixture\TemporaryFiles;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PipelineCommand::class)]
#[CoversClass(AbstractDatabase::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(SpreadsheetDatabase::class)]
#[CoversClass(DataExtractor::class)]
#[CoversClass(DataSource::class)]
#[CoversClass(DataLoader::class)]
#[CoversClass(DataTarget::class)]
#[CoversClass(Pipeline::class)]
#[CoversClass(PipelineResult::class)]
#[CoversClass(DataRules::class)]
#[CoversClass(DataTransformer::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(PipelineException::class)]
#[CoversClass(EtlTranslationResourceProvider::class)]
final class CommandRulesTest extends TestCase
{
    use TemporaryFiles;

    /** @return iterable<string, array{string, string}> */
    public static function validRuleFiles(): iterable
    {
        yield 'rule object' => ['trim-product-names.php', 'Laptop'];
        yield 'callable array' => ['callable-product-names.php', 'Laptop'];
        yield 'empty rules' => ['empty.php', ' Laptop '];
        yield 'ordered object and callable rules' => ['trim-and-prefix-product-names.php', 'Product: Laptop'];
    }

    #[DataProvider('validRuleFiles')]
    public function testRulesTransformARealXlsxBeforeLoadingSqlite(string $rules, string $name): void
    {
        $source = $this->directory . '/products.xlsx';
        $target = $this->directory . '/products.sqlite';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet('products', [['id' => 'P001', 'name' => ' Laptop ']], true);
        (new SpreadsheetDatabase($spreadsheet))->save($source);
        $before = hash_file('sha256', $source);
        $tester = new CommandTester(new PipelineCommand());
        $status = $tester->execute([
            'source' => $source,
            'target' => $target,
            '--rules' => __DIR__ . '/../../fixtures/rules/' . $rules,
            '--no-confirm' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $database = new DoctrineDatabase($target);
        $this->assertSame([['id' => 'P001', 'name' => $name]], $database->data(['table' => 'products']));
        $this->assertSame($before, hash_file('sha256', $source));
        if ($rules !== 'empty.php') {
            $this->assertStringContainsString('Transform', $tester->getDisplay());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidRuleFiles(): iterable
    {
        yield 'missing file' => ['missing.php', 'Rules file must exist and be readable'];
        yield 'directory' => ['.', 'Rules file must exist and be readable'];
        yield 'non-array return' => ['not-an-array.php', 'Rules file must return an array; null given.'];
        yield 'invalid rule' => ['invalid-rule.php', 'Rule "invalid" must implement DataRulesInterface or be callable; int given.'];
    }

    #[DataProvider('invalidRuleFiles')]
    public function testInvalidRuleFilesProduceTranslatableConfigurationErrors(string $file, string $message): void
    {
        $tester = new CommandTester(new PipelineCommand());
        try {
            $tester->execute(['source' => 'unused.xlsx', '--rules' => __DIR__ . '/../../fixtures/rules/' . $file]);
            $this->fail('Invalid rules must be rejected before starting the pipeline.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
    }

    public function testLoadingRulesPreservesTheOriginalExceptionAsItsCause(): void
    {
        $tester = new CommandTester(new PipelineCommand());
        try {
            $tester->execute(['source' => 'unused.xlsx', '--rules' => __DIR__ . '/../../fixtures/rules/unloadable.php']);
            $this->fail('A rule configuration that throws must be reported.');
        } catch (InvalidArgumentException $exception) {
            $cause = $exception->getPrevious();
            $this->assertInstanceOf(RuntimeException::class, $cause);
            $this->assertSame('Cannot load test rules.', $cause->getMessage());
            $this->assertStringContainsString('Could not load rules file', $exception->getMessage());
        }
    }

    public function testUnreadableRuleFilesAreRejected(): void
    {
        $file = $this->directory . '/unreadable.php';
        file_put_contents($file, '<?php return [];');
        chmod($file, 0200);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rules file must exist and be readable');
        (new CommandTester(new PipelineCommand()))->execute(['source' => 'unused.xlsx', '--rules' => $file]);
    }
}
