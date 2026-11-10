<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Transform;

use Derafu\ETL\Transform\DataRules;
use Derafu\Value\Exception\TypeMismatchException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataRules::class)]
final class DataRulesTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function invalidResults(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'text' => ['invalid'];
        yield 'integer' => [42];
    }

    #[DataProvider('invalidResults')]
    public function testInvalidRuleResultsAreRejectedBeforeTheNextRule(mixed $result): void
    {
        $nextCalled = false;
        $rules = new DataRules([
            'invalid' => static fn (array $data): mixed => $result,
            'next' => static function (array $data) use (&$nextCalled): array {
                $nextCalled = true;
                return $data;
            },
        ]);

        try {
            $rules->transform(['products' => [['id' => 1]]]);
            $this->fail('An invalid rule result must abort the transformation.');
        } catch (TypeMismatchException $exception) {
            $this->assertStringContainsString('rules.invalid', $exception->getMessage());
            $this->assertStringContainsString(DataRules::class, $exception->getMessage());
            $this->assertStringContainsString(get_debug_type($result) . ' given', $exception->getMessage());
        }
        $this->assertFalse($nextCalled);
    }

    public function testEmptyArrayResultsArePassedToTheNextRule(): void
    {
        $rules = new DataRules([
            static fn (array $data): array => [],
            static function (array $data): array {
                self::assertSame([], $data);
                return ['products' => []];
            },
        ]);

        $this->assertSame(['products' => []], $rules->transform(['products' => [['id' => 1]]]));
    }
}
