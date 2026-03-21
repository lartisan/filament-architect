<?php

namespace Lartisan\Architect\Support;

use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\RegenerationPlan;

class BlueprintGenerationHookRegistry
{
    /**
     * @var array<int, callable(Blueprint, BlueprintData, RegenerationPlan, bool): void>
     */
    protected array $afterGenerateCallbacks = [];

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
        $this->afterGenerateCallbacks = [];

        return $this;
    }
}
