<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Schema\Enum;

/**
 * Type of a database index.
 *
 * The backed values are the ones persisted in the schema sheet of a
 * spreadsheet, so they must not change.
 */
enum IndexType: string
{
    case REGULAR = 'regular';
    case UNIQUE = 'unique';
    case FULLTEXT = 'fulltext';
    case SPATIAL = 'spatial';
}
