<?php

namespace Lartisan\Architect\Support;

use Closure;
use InvalidArgumentException;
use Lartisan\Architect\Contracts\ArchitectBlockProvider;

class ArchitectBlockRegistry
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $blocks = [];

    /**
     * @var array<int, ArchitectBlockProvider|Closure(): array<int, array<string, mixed>>>
     */
    protected array $providers = [];

    /**
     * @param  array<string, mixed>  $block
     */
    public function register(array $block): static
    {
        $type = $block['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new InvalidArgumentException('Architect blocks must define a non-empty [type].');
        }

        $this->blocks[] = $block;

        return $this;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function registerMany(array $blocks): static
    {
        foreach ($blocks as $block) {
            $this->register($block);
        }

        return $this;
    }

    public function extend(ArchitectBlockProvider|Closure $provider): static
    {
        $this->providers[] = $provider;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $blocks = [
            ...$this->blocks,
            ...$this->resolveProviderBlocks(),
        ];

        return array_values(collect($blocks)
            ->filter(fn (array $block): bool => filled($block['type'] ?? null))
            ->unique(fn (array $block): string => (string) $block['type'])
            ->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $baseBlocks
     * @return array<int, array<string, mixed>>
     */
    public function merge(array $baseBlocks): array
    {
        return array_values(collect([
            ...$baseBlocks,
            ...$this->all(),
        ])
            ->filter(fn (array $block): bool => filled($block['type'] ?? null))
            ->unique(fn (array $block): string => (string) $block['type'])
            ->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function resolveProviderBlocks(): array
    {
        return collect($this->providers)
            ->flatMap(function (ArchitectBlockProvider|Closure $provider): array {
                if ($provider instanceof ArchitectBlockProvider) {
                    return $provider->blocks();
                }

                return value($provider);
            })
            ->filter(fn ($block): bool => is_array($block))
            ->values()
            ->all();
    }

    public function flush(): static
    {
        $this->blocks = [];
        $this->providers = [];

        return $this;
    }
}
