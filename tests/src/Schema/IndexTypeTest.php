<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Schema;

use Derafu\ETL\Schema\Column;
use Derafu\ETL\Schema\Contract\SchemaInterface;
use Derafu\ETL\Schema\Enum\IndexType;
use Derafu\ETL\Schema\Index;
use Derafu\ETL\Schema\Schema;
use Derafu\ETL\Schema\Source\DoctrineSchemaSource;
use Derafu\ETL\Schema\Source\SpreadsheetSchemaSource;
use Derafu\ETL\Schema\Table;
use Derafu\ETL\Schema\Target\DoctrineSchemaTarget;
use Derafu\ETL\Schema\Target\MarkdownSchemaTarget;
use Derafu\ETL\Schema\Target\SpreadsheetSchemaTarget;
use Derafu\ETL\Schema\Target\TextSchemaTarget;
use Derafu\Spreadsheet\SpreadsheetDumper;
use Derafu\Spreadsheet\SpreadsheetLoader;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Index type and clustered flag across the model, the sources and the targets.
 */
#[CoversClass(Index::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
#[CoversClass(DoctrineSchemaSource::class)]
#[CoversClass(DoctrineSchemaTarget::class)]
#[CoversClass(SpreadsheetSchemaSource::class)]
#[CoversClass(SpreadsheetSchemaTarget::class)]
#[CoversClass(TextSchemaTarget::class)]
#[CoversClass(MarkdownSchemaTarget::class)]
final class IndexTypeTest extends TestCase
{
    private function createSchema(): SchemaInterface
    {
        $table = new Table('doc');
        $table->addColumn(new Column('id', 'integer'));
        $table->addColumn(new Column('code', 'string'));
        $table->addColumn(new Column('body', 'text'));
        $table->addIndex(new Index('ix_regular', ['id']));
        $table->addIndex(new Index('ix_unique', ['code'], IndexType::UNIQUE));
        $table->addIndex(new Index('ix_fulltext', ['body'], IndexType::FULLTEXT));
        $table->addIndex(new Index('ix_clustered', ['id', 'code'], IndexType::REGULAR, true));

        $schema = new Schema();
        $schema->addTable($table);

        return $schema;
    }

    /**
     * @return array<string, array{IndexType, bool}>
     */
    private function indexSummary(SchemaInterface $schema): array
    {
        $summary = [];
        foreach ($schema->getTable('doc')->getIndexes() as $index) {
            $summary[$index->getName()] = [$index->getType(), $index->isClustered()];
        }
        ksort($summary);

        return $summary;
    }

    public function testModel(): void
    {
        $index = new Index('ix', ['a']);

        $this->assertSame(IndexType::REGULAR, $index->getType());
        $this->assertFalse($index->isUnique());
        $this->assertFalse($index->isClustered());

        $index->setType(IndexType::UNIQUE)->setClustered(true);

        $this->assertSame(IndexType::UNIQUE, $index->getType());
        $this->assertTrue($index->isUnique());
        $this->assertTrue($index->isClustered());
    }

    public function testSpreadsheetRoundTripKeepsTypeAndClustered(): void
    {
        $schema = $this->createSchema();

        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema($schema);
        $json = (new SpreadsheetDumper())->dumpToString($spreadsheet, 'json');
        $loaded = (new SpreadsheetLoader())->loadFromString($json, 'json');
        $regenerated = (new SpreadsheetSchemaSource())->extractSchema($loaded);

        $this->assertSame(
            $this->indexSummary($schema),
            $this->indexSummary($regenerated)
        );
    }

    public function testDoctrineRoundTripKeepsTypeAndClustered(): void
    {
        $schema = $this->createSchema();

        $doctrineSchema = (new DoctrineSchemaTarget())->applySchema($schema);
        $regenerated = (new DoctrineSchemaSource())->extractSchema($doctrineSchema);

        $this->assertSame(
            $this->indexSummary($schema),
            $this->indexSummary($regenerated)
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, IndexType, bool}>
     */
    public static function legacyProvider(): array
    {
        return [
            'regular' => [['unique' => false, 'flags' => []], IndexType::REGULAR, false],
            'unique' => [['unique' => true, 'flags' => []], IndexType::UNIQUE, false],
            'clustered flag' => [['unique' => false, 'flags' => ['clustered']], IndexType::REGULAR, true],
            'fulltext flag' => [['unique' => false, 'flags' => ['fulltext']], IndexType::FULLTEXT, false],
            'spatial flag' => [['unique' => false, 'flags' => ['spatial']], IndexType::SPATIAL, false],
            'unique and clustered' => [['unique' => true, 'flags' => ['clustered']], IndexType::UNIQUE, true],
        ];
    }

    /**
     * @param array<string, mixed> $legacy
     */
    #[DataProvider('legacyProvider')]
    public function testLegacySpreadsheetFormatIsStillRead(
        array $legacy,
        IndexType $type,
        bool $clustered
    ): void {
        $schema = $this->extractWithIndexProperties(['columns' => ['code']] + $legacy);

        $index = $schema->getTable('doc')->getIndex('ix');
        $this->assertNotNull($index);
        $this->assertSame($type, $index->getType());
        $this->assertSame($clustered, $index->isClustered());
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->expectException(TranslatableRuntimeException::class);
        $this->expectExceptionMessage('Invalid type "btree" for index "ix" in table "doc".');

        $this->extractWithIndexProperties(['columns' => ['code'], 'type' => 'btree']);
    }

    public function testUnknownLegacyFlagIsRejected(): void
    {
        $this->expectException(TranslatableRuntimeException::class);
        $this->expectExceptionMessage('Unsupported flag "partial" for index "ix" in table "doc".');

        $this->extractWithIndexProperties(['columns' => ['code'], 'flags' => ['partial']]);
    }

    public function testTextAndMarkdownShowTypeAndClustered(): void
    {
        $schema = $this->createSchema();

        $text = (new TextSchemaTarget())->applySchema($schema);
        $this->assertStringContainsString('ix_regular (INDEX): id', $text);
        $this->assertStringContainsString('ix_unique (UNIQUE INDEX): code', $text);
        $this->assertStringContainsString('ix_fulltext (FULLTEXT INDEX): body', $text);
        $this->assertStringContainsString('ix_clustered (INDEX): id, code', $text);
        $this->assertSame(1, substr_count($text, 'CLUSTERED'));

        $markdown = (new MarkdownSchemaTarget())->applySchema($schema);
        $this->assertStringContainsString('| ix_regular | `id` | INDEX |  |', $markdown);
        $this->assertStringContainsString('| ix_unique | `code` | UNIQUE |  |', $markdown);
        $this->assertStringContainsString('| ix_fulltext | `body` | FULLTEXT |  |', $markdown);
        $this->assertStringContainsString('| ix_clustered | `id`, `code` | INDEX | CLUSTERED |', $markdown);
    }

    /**
     * Dump a one-index schema, replace the index properties and load it back.
     *
     * @param array<string, mixed> $properties
     */
    private function extractWithIndexProperties(array $properties): SchemaInterface
    {
        $table = new Table('doc');
        $table->addColumn(new Column('code', 'string'));
        $table->addIndex(new Index('ix', ['code']));
        $schema = new Schema();
        $schema->addTable($table);

        $spreadsheet = (new SpreadsheetSchemaTarget())->applySchema($schema);
        $data = json_decode(
            (new SpreadsheetDumper())->dumpToString($spreadsheet, 'json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($data['__schema'] as &$row) {
            if ($row['type'] === 'index') {
                $row['properties'] = $properties;
            }
        }
        unset($row);

        $loaded = (new SpreadsheetLoader())->loadFromString(
            json_encode($data, JSON_THROW_ON_ERROR),
            'json'
        );

        return (new SpreadsheetSchemaSource())->extractSchema($loaded);
    }
}
