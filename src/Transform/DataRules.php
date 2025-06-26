<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ETL\Transform;

use Derafu\ETL\Transform\Contract\DataRulesInterface;

/**
 * DataRules is a class that contains the rules for the transformation of the
 * data and applies them to the data.
 */
final class DataRules implements DataRulesInterface
{
    /**
     * Constructor.
     *
     * @param array $rules The rules.
     */
    public function __construct(private readonly array $rules)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function transform(array $data): array
    {
        foreach ($this->rules as $rule) {
            // If the rule is an instance of DataRulesInterface, transform the
            // data using the rules.
            if ($rule instanceof DataRulesInterface) {
                $data = $rule->transform($data);
            }

            // If the rule is a callable, apply it to the data.
            if (is_callable($rule)) {
                $data = $rule($data);
            }
        }

        return $data;
    }
}
