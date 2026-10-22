<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Database;

use Derafu\ETL\Database\Abstract\AbstractDatabase;
use Derafu\ETL\Database\DoctrineDatabase;
use Derafu\ETL\Translation\EtlTranslationResourceProvider;
use Derafu\TestsETL\Fixture\StringCountConnection;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineDatabase::class)]
#[CoversClass(AbstractDatabase::class)]
#[UsesClass(EtlTranslationResourceProvider::class)]
final class DoctrineRowsTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function invalidRows(): iterable
    {
        yield 'table rows are not an array' => ['invalid', 'must be an array'];
        yield 'first row is not an array' => [[123], 'must be a non-empty array'];
        yield 'first row is empty' => [[[]], 'must be a non-empty array'];
        yield 'later row is not an array' => [[['id' => 1, 'name' => 'Laptop'], 123], 'must be a non-empty array'];
        yield 'later row is empty' => [[['id' => 1, 'name' => 'Laptop'], []], 'must be a non-empty array'];
        yield 'missing column' => [[['id' => 1, 'name' => 'Laptop'], ['id' => 2]], 'must have the same columns'];
        yield 'additional column' => [[['id' => 1, 'name' => 'Laptop'], ['id' => 2, 'name' => 'Desktop', 'extra' => true]], 'must have the same columns'];
    }

    #[DataProvider('invalidRows')]
    public function testInvalidDataIsRejectedBeforeOpeningATransactionOrLoadingAnyTable(mixed $rows, string $message): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name TEXT)');
        $target = new DoctrineDatabase($connection);
        try {
            $target->load(['records' => [['id' => 99, 'name' => 'Must not be inserted']], 'invalid' => $rows]);
            $this->fail('Invalid rows must be rejected before loading begins.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
        $this->assertSame(0, $connection->getTransactionNestingLevel());
        $this->assertSame([], $connection->fetchAllAssociative('SELECT * FROM records'));
    }

    public function testSparseRowsAndDifferentColumnOrdersKeepValuesAndNullsAligned(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name TEXT, note TEXT)');
        $target = new DoctrineDatabase($connection);
        $rows = [
            3 => ['id' => 1, 'name' => 'Laptop', 'note' => null],
            8 => ['note' => 'Second', 'name' => 'Desktop', 'id' => 2],
        ];

        $this->assertSame(2, $target->load(['empty' => [], 'records' => $rows]));
        $this->assertSame([
            ['id' => 1, 'name' => 'Laptop', 'note' => null],
            ['id' => 2, 'name' => 'Desktop', 'note' => 'Second'],
        ], $connection->fetchAllAssociative('SELECT * FROM records ORDER BY id'));
        $this->assertSame(0, $target->load(['records' => []]));
        $this->assertSame(0, $connection->getTransactionNestingLevel());
        $this->assertSame([3, 8], array_keys($rows));
        $this->assertSame(['note', 'name', 'id'], array_keys($rows[8]));
    }

    public function testRealSqliteStatementCountsReturnedAsStringsAreNormalizedToIntegers(): void
    {
        $target = new DoctrineDatabase([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => StringCountConnection::class,
        ]);
        $connection = $target->connection();
        $this->assertInstanceOf(StringCountConnection::class, $connection);
        $connection->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name TEXT)');
        $this->assertSame('1', $connection->executeStatement("INSERT INTO records VALUES (1, 'First')"));

        $this->assertSame(1, $target->load("INSERT INTO records VALUES (2, 'Second')"));
        $this->assertSame(2, $target->load(['records' => [['id' => 3, 'name' => 'Third'], ['id' => 4, 'name' => 'Fourth']]]));
        $this->assertSame(0, $target->load("UPDATE records SET name = 'Missing' WHERE id = 999"));
        $this->assertSame(4, $connection->fetchOne('SELECT COUNT(*) FROM records'));
    }
}
