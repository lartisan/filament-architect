<?php

namespace Lartisan\Architect\Support;

use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\RegenerationPlan;

class BlueprintGenerationHookRegistry
{
    /**
     * Callbacks invoked before generation begins. Any callback may throw to abort.
     *
     * @var array<int, callable(BlueprintData): void>
     */
    protected array $beforeGenerateCallbacks = [];

    /**
     * @var array<int, callable(Blueprint, BlueprintData, RegenerationPlan, bool): void>
     */
    protected array $afterGenerateCallbacks = [];

    /**
     * @param  callable(BlueprintData): void  $callback
     */
    public function beforeGenerate(callable $callback): static
    {
        $this->beforeGenerateCallbacks[] = $callback;

        return $this;
    }

    /**
     * Run all before-generate callbacks. Any callback may throw to abort generation.
     */
    public function runBeforeGenerate(BlueprintData $blueprintData): void
    {
        foreach ($this->beforeGenerateCallbacks as $callback) {
            $callback($blueprintData);
        }
    }

    /**
     * @param  callable(Blueprint, BlueprintData, RegenerationPlan, bool): void  $callback
     */
    public function afterGenerate(callable $callback): static
    {
        $this->afterGenerateCallbacks[] = $callback;

        return $this;
    }

    public function runAfterGenerate(Blueprint $blueprint, BlueprintData $blueprintData, RegenerationPlan $plan, bool $shouldRunMigration): void
    {
        foreach ($this->afterGenerateCallbacks as $callback) {
            $callback($blueprint, $blueprintData, $plan, $shouldRunMigration);
        }
    }

    public function flush(): static
    {
        $this->beforeGenerateCallbacks = [];
        $this->afterGenerateCallbacks = [];

        return $this;
    }
}
