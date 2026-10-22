<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsETL\Fixture;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;

/** A real DBAL connection that exposes driver counts as decimal strings. */
final class StringCountConnection extends Connection
{
    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int<0, max>, string|Type|ParameterType|ArrayParameterType>|array<string, string|Type|ParameterType|ArrayParameterType> $types
     * @return numeric-string
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): string
    {
        return (string) parent::executeStatement($sql, $params, $types);
    }
}
