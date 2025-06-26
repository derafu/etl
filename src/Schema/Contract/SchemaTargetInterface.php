<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Schema\Contract;

use RuntimeException;

/**
 * SchemaTarget is responsible for applying a schema to a specific target.
 *
 * This interface defines the minimum contract for any schema target adapter
 * that can convert a SchemaInterface into another representation or system.
 */
interface SchemaTargetInterface
{
    /**
     * Apply the given schema to the target.
     *
     * @param SchemaInterface $schema The schema to apply.
     * @return mixed The result of applying the schema, specific to each target
     * implementation.
     * @throws RuntimeException When the schema cannot be applied.
     */
    public function applySchema(SchemaInterface $schema): mixed;
}
