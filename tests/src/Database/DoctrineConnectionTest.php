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
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DoctrineDatabase::class)]
#[UsesClass(AbstractDatabase::class)]
#[UsesClass(EtlTranslationResourceProvider::class)]
final class DoctrineConnectionTest extends TestCase
{
    /** @return list<string> */
    private static function stringOptions(): array
    {
        return [
            'application_name', 'charset', 'connectstring', 'dbname',
            'gssencmode', 'host', 'instancename', 'password', 'path',
            'serverVersion', 'servicename', 'ssl_ca', 'ssl_capath', 'ssl_cert',
            'ssl_cipher', 'ssl_key', 'sslcert', 'sslcrl', 'sslkey', 'sslmode',
            'sslrootcert', 'unix_socket', 'user', 'sid',
        ];
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidParameters(): iterable
    {
        foreach (self::stringOptions() as $option) {
            yield $option => [[$option => []]];
        }
        foreach (['memory', 'persistent', 'pooled', 'service', 'keepReplica'] as $option) {
            yield $option => [[$option => 'true']];
        }
        foreach (['port', 'sessionMode'] as $option) {
            yield $option => [[$option => '123']];
        }
        foreach (['driverOptions', 'defaultTableOptions', 'primary', 'replica'] as $option) {
            yield $option => [[$option => 'invalid']];
        }
        yield 'numeric default table option' => [['defaultTableOptions' => ['invalid']]];
        yield 'unknown driver' => [['driver' => 'unknown']];
        yield 'non-string driver' => [['driver' => []]];
        yield 'invalid driver class' => [['driverClass' => stdClass::class]];
        yield 'invalid wrapper class' => [['wrapperClass' => stdClass::class]];
        yield 'invalid primary option' => [['primary' => ['port' => '123']]];
        yield 'invalid replica' => [['replica' => ['invalid']]];
        yield 'invalid replica option' => [['replica' => [['memory' => 'true']]]];
        foreach (['user', 'password'] as $option) {
            foreach ([123, false, 1.5, new stdClass()] as $value) {
                yield $option . ' rejects ' . get_debug_type($value) => [[$option => $value]];
            }
            yield $option . ' rejects invalid primary credentials' => [['primary' => [$option => []]]];
            yield $option . ' rejects invalid replica credentials' => [['replica' => [[$option => []]]]];
        }
    }

    /** @param array<array-key, mixed> $parameters */
    #[DataProvider('invalidParameters')]
    public function testInvalidKnownOptionsProduceATranslatableConfigurationException(array $parameters): void
    {
        try {
            new DoctrineDatabase(array_replace(['driver' => 'pdo_sqlite', 'memory' => true], $parameters));
            $this->fail('Invalid connection parameters must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
    }

    public function testCustomDriversAndExtensionOptionsArePreservedWhileConnectingToSqlite(): void
    {
        $parameters = [
            'driverClass' => Driver::class,
            'memory' => true,
            'user' => '',
            'password' => '',
            'driverOptions' => [],
            'defaultTableOptions' => ['custom' => ['retained' => true]],
            'customExtension' => ['retained' => true],
            'keepReplica' => false,
            'primary' => ['host' => 'primary', 'customExtension' => true],
            'replica' => [3 => ['host' => 'replica', 'customExtension' => true]],
        ];
        $database = new DoctrineDatabase($parameters);
        $this->assertSame($parameters, $database->connection()->getParams());
        $this->assertSame(42, $database->connection()->fetchOne('SELECT 42'));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function nullCredentials(): iterable
    {
        foreach (['user', 'password'] as $option) {
            yield $option => [[$option => null], $option];
            yield 'primary.' . $option => [['primary' => [$option => null]], 'primary.' . $option];
            yield 'replica.' . $option => [['replica' => [3 => [$option => null]]], 'replica.' . $option];
        }
    }

    /**
     * This rejection is temporary. See the DBAL boundary restriction documented
     * in DoctrineDatabase::__construct() before changing these expectations.
     *
     * @param array<array-key, mixed> $parameters
     */
    #[DataProvider('nullCredentials')]
    public function testNullCredentialsAreTemporarilyRejectedAtTheDbalBoundary(array $parameters, string $option): void
    {
        try {
            new DoctrineDatabase(array_replace(['driver' => 'pdo_sqlite', 'memory' => true], $parameters));
            $this->fail('Null credentials must be rejected by the temporary DBAL boundary restriction.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Doctrine option "' . $option . '" must be a string; null given.', $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new EtlTranslationResourceProvider()]);
            $this->assertNotSame($exception->getMessage(), $exception->trans($translator));
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function validCredentials(): iterable
    {
        yield 'absent credentials' => [[]];
        yield 'user without password' => [['user' => 'user']];
        yield 'password without user' => [['password' => 'password']];
        yield 'string credentials' => [['user' => 'user', 'password' => 'password']];
        yield 'empty strings' => [['user' => '', 'password' => '']];
    }

    /** @param array<string, string> $credentials */
    #[DataProvider('validCredentials')]
    public function testStringAndAbsentCredentialsArePreservedWhileConnectingToSqlite(array $credentials): void
    {
        $parameters = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            ...$credentials,
            'primary' => $credentials,
            'replica' => [3 => $credentials],
        ];
        $database = new DoctrineDatabase($parameters);
        $connection = $database->connection();
        $this->assertSame($parameters, $connection->getParams());
        $connection->executeStatement('CREATE TABLE records (id INTEGER PRIMARY KEY, name TEXT)');
        $this->assertSame(1, $database->load(['records' => [['id' => 1, 'name' => 'Laptop']]]));
        $this->assertSame([['id' => 1, 'name' => 'Laptop']], $connection->fetchAllAssociative('SELECT * FROM records'));
        $this->assertSame($parameters, $connection->getParams());
    }

    public function testKnownOptionsAreValidatedWithoutDiscardingValuesForOtherDrivers(): void
    {
        $parameters = ['driver' => 'pdo_sqlite', 'memory' => true];
        foreach (self::stringOptions() as $option) {
            $parameters[$option] = '';
        }
        foreach (['persistent', 'pooled', 'service', 'keepReplica'] as $option) {
            $parameters[$option] = false;
        }
        $parameters['port'] = 123;
        $parameters['sessionMode'] = 0;
        $database = new DoctrineDatabase($parameters);
        $this->assertSame($parameters, $database->connection()->getParams());
        $this->assertSame(42, $database->connection()->fetchOne('SELECT 42'));
    }
}
