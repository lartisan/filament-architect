<?php

namespace Lartisan\Architect\Contracts;

interface ArchitectBlockProvider
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocks(): array;
}
