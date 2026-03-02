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
}
