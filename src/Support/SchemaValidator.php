<?php

namespace Lartisan\Architect\Support;

use Lartisan\Architect\Exceptions\InvalidBlueprintException;

class SchemaValidator
{
    public static function validate(array $columns): void
    {
        $names = array_column($columns, 'name');

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidBlueprintException(__('Duplicate column names found in the table definition.'));
        }

        foreach ($names as $name) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
                throw new InvalidBlueprintException(__("Column name ':name' is invalid. Use only lowercase letters, numbers, and underscores.", ['name' => $name]));
            }
        }
    }
}
