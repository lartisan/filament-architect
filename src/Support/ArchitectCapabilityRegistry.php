<?php

namespace Lartisan\Architect\Support;

use Closure;
use Lartisan\Architect\Contracts\ArchitectCapabilityResolver;

class ArchitectCapabilityRegistry implements ArchitectCapabilityResolver
{
    /**
     * @var array<string, bool|Closure(): bool>
     */
    protected array $capabilities = [];

    public function define(string $capability, bool|Closure $resolver = true): static
    {
        $this->capabilities[$capability] = $resolver;

        return $this;
    }

    public function forget(string $capability): static
    {
        unset($this->capabilities[$capability]);

        return $this;
    }

    public function has(string $capability): bool
    {
        if (! array_key_exists($capability, $this->capabilities)) {
            return false;
        }

        return (bool) value($this->capabilities[$capability]);
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return collect($this->capabilities)
            ->map(fn (bool|Closure $resolver): bool => (bool) value($resolver))
            ->all();
    }

    public function flush(): static
    {
        $this->capabilities = [];

        return $this;
    }
}
