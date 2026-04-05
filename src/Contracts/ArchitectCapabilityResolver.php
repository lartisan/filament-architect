<?php

namespace Lartisan\Architect\Contracts;

interface ArchitectCapabilityResolver
{
    public function has(string $capability): bool;
}
