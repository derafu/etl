<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Exception;

use Derafu\Translation\Exception\Core\TranslatableRuntimeException;

/**
 * ETLException is the base exception for all ETL-related exceptions.
 */
class ETLException extends TranslatableRuntimeException
{
}
