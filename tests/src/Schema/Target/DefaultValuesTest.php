<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema\Target;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\MarkdownSchemaTarget;
use Derafu\ETL\Schema\Target\SqliteSchemaTarget;
use Derafu\ETL\Schema\Target\TextSchemaTarget;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(TextSchemaTarget::class)]
#[CoversClass(MarkdownSchemaTarget::class)]
#[CoversClass(SqliteSchemaTarget::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Table::class)]
#[UsesClass(Column::class)]
#[UsesClass(EtlTranslationResourceProvider::class)]
final class DefaultValuesTest extends TestCase
{
    private function schema(mixed $value, string $type = 'string'): Schema
    {
        $column = new Column('value', $type);
        $column->setDefault($value);
        $table = new Table('records');
        $table->addColumn($column);
        $schema = new Schema();
        $schema->addTable($table);

        return $schema;
    }

    /** @return iterable<string, array{mixed, string, string, mixed}> */
    public static function supportedValues(): iterable
    {
        yield 'null' => [null, 'string', '', null];
        yield 'empty string' => ['', 'string', "''", ''];
        yield 'quoted text' => ["O'Reilly", 'string', "'O'Reilly'", "O'Reilly"];
        yield 'zero' => [0, 'integer', '0', 0];
        yield 'integer' => [42, 'integer', '42', 42];
        yield 'float' => [1.5, 'float', '1.5', 1.5];
        yield 'negative float' => [-2.5, 'float', '-2.5', -2.5];
        yield 'true' => [true, 'boolean', 'true', 1];
        yield 'false' => [false, 'boolean', 'false', 0];
        yield 'stringable literal' => [require dirname(__DIR__, 3) . '/fixtures/stringable-default.php', 'string', "'CURRENT_TIMESTAMP'", 'CURRENT_TIMESTAMP'];
    }

    #[DataProvider('supportedValues')]
    public function testDocumentationDisplaysSupportedDefaultsExplicitly(mixed $value, string $type, string $display, mixed $stored): void
    {
        $schema = $this->schema($value, $type);
        $text = (new TextSchemaTarget())->applySchema($schema);
        $markdown = (new MarkdownSchemaTarget())->applySchema($schema);

        if ($value === null) {
            $this->assertStringNotContainsString('default=', $text);
            $this->assertStringNotContainsString('DEFAULT:', $markdown);
        } else {
            $this->assertStringContainsString('default=' . $display, $text);
            $this->assertStringContainsString('DEFAULT: ' . $display, $markdown);
        }
        $column = $schema->getTable('records')?->getColumn('value');
        $this->assertNotNull($column);
        $this->assertSame($value, $column->getDefault());
    }

    #[DataProvider('supportedValues')]
    public function testSqliteExecutesTheGeneratedDefaultAsALiteral(mixed $value, string $type, string $display, mixed $stored): void
    {
        $schema = $this->schema($value, $type);
        $sql = (new SqliteSchemaTarget())->applySchema($schema);
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($sql);
        $pdo->exec('INSERT INTO records DEFAULT VALUES');
        $statement = $pdo->query('SELECT value FROM records');
        $this->assertNotFalse($statement);
        $this->assertSame($stored, $statement->fetchColumn());

        if ($value === null) {
            $this->assertStringNotContainsString(' DEFAULT ', $sql);
        }
        $column = $schema->getTable('records')?->getColumn('value');
        $this->assertNotNull($column);
        $this->assertSame($value, $column->getDefault());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unsupportedValues(): iterable
    {
        foreach (['text', 'markdown', 'sqlite'] as $format) {
            foreach (['array' => ['value'], 'object' => new stdClass(), 'infinity' => INF, 'negative infinity' => -INF, 'nan' => NAN] as $case => $value) {
                yield $format . ' ' . $case => [$format, $value];
            }
        }
    }

    #[DataProvider('unsupportedValues')]
    public function testUnsupportedDefaultsProduceATranslatableException(string $format, mixed $value): void
    {
        $target = match ($format) {
            'text' => new TextSchemaTarget(),
            'markdown' => new MarkdownSchemaTarget(),
            'sqlite' => new SqliteSchemaTarget(),
            default => $this->fail('Unknown test format.'),
        };

        try {
            $target->applySchema($this->schema($value));
            $this->fail('An unsupported default must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Default value for column "value" must be', $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertStringContainsString('El valor predeterminado de la columna "value" debe ser', $exception->trans($translator));
        }
    }

    public function testResourceDefaultsAreRejectedByEveryTarget(): void
    {
        $resource = fopen('php://memory', 'r+');
        $this->assertNotFalse($resource);
        try {
            foreach ([new TextSchemaTarget(), new MarkdownSchemaTarget(), new SqliteSchemaTarget()] as $target) {
                try {
                    $target->applySchema($this->schema($resource));
                    $this->fail('A resource cannot be a default literal.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString('resource (stream) given.', $exception->getMessage());
                }
            }
        } finally {
            fclose($resource);
        }
    }
}
