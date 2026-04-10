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
    protected array $databaseStepExtensions = [];

    /**
     * @var array<int, Closure(): mixed>
     */
    protected array $existingResourcesExtensions = [];

    /**
     * @var array<int, Closure(): mixed>
     */
    protected array $blueprintsTableRecordActions = [];

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

    public function registerDatabaseStepExtension(Closure $factory): static
    {
        $this->databaseStepExtensions[] = $factory;

        return $this;
    }

    public function registerExistingResourcesExtension(Closure $factory): static
    {
        $this->existingResourcesExtensions[] = $factory;

        return $this;
    }

    public function registerBlueprintsTableRecordActions(Closure $factory): static
    {
        $this->blueprintsTableRecordActions[] = $factory;

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
    public function databaseStepExtensions(): array
    {
        return $this->resolveFactories($this->databaseStepExtensions);
    }

    /**
     * @return array<int, mixed>
     */
    public function existingResourcesExtensions(): array
    {
        return $this->resolveFactories($this->existingResourcesExtensions);
    }

    /**
     * @return array<int, mixed>
     */
    public function blueprintsTableRecordActions(): array
    {
        return $this->resolveFactories($this->blueprintsTableRecordActions);
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
        $this->databaseStepExtensions = [];
        $this->existingResourcesExtensions = [];
        $this->blueprintsTableRecordActions = [];

        return $this;
    }
}
