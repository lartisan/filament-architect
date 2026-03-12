<?php

namespace Lartisan\Architect\Exceptions;

use Exception;

class InvalidBlueprintException extends Exception
{
    public static function duplicateColumns(array $duplicates): self
    {
        return new self(__('Duplicate columns found: :columns', ['columns' => implode(', ', $duplicates)]));
    }

    public static function reservedWord(string $word): self
    {
        return new self(__('The word ":word" is reserved in SQL and cannot be used as a column name.', ['word' => $word]));
    }

    public static function unsafeRequiredColumnAddition(string $table, array $columns): self
    {
        return new self(__('Cannot add required columns without defaults to existing table ":table" while it already contains data: :columns. Make the column nullable first, provide a default value, or backfill the table before making it required.', [
            'table' => $table,
            'columns' => implode(', ', $columns),
        ]));
    }
}
