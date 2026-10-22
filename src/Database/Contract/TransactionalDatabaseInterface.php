<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Database\Contract;

use Closure;

/**
 * Optional transaction support for database adapters.
 *
 * Custom adapters can implement this contract without changing DatabaseInterface.
 */
interface TransactionalDatabaseInterface extends DatabaseInterface
{
    /**
     * Execute a complete load, including an optional schema synchronization.
     *
     * Implementations preserve a caller's transaction through nesting where
     * supported. MySQL schema changes cause implicit commits: when the planned
     * synchronization requires DDL, full rollback is not guaranteed, including
     * for transactions started by the caller. Nontransactional MySQL tables
     * likewise cannot guarantee rollback of their data.
     *
     * @template TResult
     * @param Closure(): TResult $operation The synchronization and load.
     * @param DatabaseInterface|null $schemaSource The source to synchronize,
     * or null when no schema synchronization will be performed.
     * @return TResult
     */
    public function transactional(Closure $operation, ?DatabaseInterface $schemaSource = null): mixed;
}
