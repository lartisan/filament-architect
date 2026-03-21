<?php

namespace Lartisan\Architect\Support;

use Closure;

class ArchitectUiExtensionRegistry
{
    /**
     * @var array<int, Closure(): mixed>
     */
    protected array $tabs = [];

    /**
     * @var array<int, Closure(): mixed>
     */
    protected array $createEditExtensions = [];

    /**
     * @var array<int, Closure(): mixed>
     */
    protected array $existingResourcesExtensions = [];

    public function registerTab(Closure $factory): static
    {
        $this->tabs[] = $factory;

        return $this;
    }

    public function registerCreateEditExtension(Closure $factory): static
    {
        $this->createEditExtensions[] = $factory;

        return $this;
    }

    public function registerExistingResourcesExtension(Closure $factory): static
    {
        $this->existingResourcesExtensions[] = $factory;

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    public function tabs(): array
    {
        return $this->resolveFactories($this->tabs);
    }

    /**
     * @return array<int, mixed>
     */
    public function createEditExtensions(): array
    {
        return $this->resolveFactories($this->createEditExtensions);
    }

    /**
     * @return array<int, mixed>
     */
    public function existingResourcesExtensions(): array
    {
        return $this->resolveFactories($this->existingResourcesExtensions);
    }

    /**
     * @param  array<int, Closure(): mixed>  $factories
     * @return array<int, mixed>
     */
    protected function resolveFactories(array $factories): array
    {
        return collect($factories)
            ->flatMap(function (Closure $factory): array {
                $resolved = value($factory);

                if ($resolved === null) {
                    return [];
                }

                return is_array($resolved) ? $resolved : [$resolved];
            })
            ->values()
            ->all();
    }

    public function flush(): static
    {
        $this->tabs = [];
        $this->createEditExtensions = [];
        $this->existingResourcesExtensions = [];

        return $this;
    }
}
