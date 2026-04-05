<?php

namespace Lartisan\Architect\Contracts;

use Lartisan\Architect\ValueObjects\BlueprintData;

interface BlueprintImporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function import(array $context = []): BlueprintData;
}
