<?php

namespace Lartisan\Architect\Support\Concerns;

trait Resolvable
{
    public static function make(...$arguments): static
    {
        return app(static::class, $arguments);
    }
}
