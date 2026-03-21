<?php

namespace Lartisan\Architect\Contracts;

interface ProjectScanner
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function scan(): array;
}
