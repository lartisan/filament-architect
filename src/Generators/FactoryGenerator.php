<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Lartisan\Architect\ValueObjects\BlueprintData;

readonly class FactoryGenerator extends AbstractGenerator
{
    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('factory');

        return $this->replacePlaceholders($stub, [
            '{{ namespace }}' => config('architect.factories_namespace', 'Database\\Factories'),
            '{{ model_namespace }}' => config('architect.models_namespace', 'App\\Models'),
            '{{ model_class }}' => $blueprint->modelName,
            '{{ factory_class }}' => "{$blueprint->modelName}Factory",
            '{{ factory_definitions }}' => $blueprint->getFactoryDefinitions(),
        ]);
    }

    public function generate(BlueprintData $blueprint): string
    {
        if (! $blueprint->generateFactory) {
            return '';
        }

        $factoryName = "{$blueprint->modelName}Factory";
        $path = database_path("factories/{$factoryName}.php");

        $this->ensureDirectoryExists($path);

        if (File::exists($path) && ! $blueprint->overwriteTable) {
            return $path;
        }

        File::put($path, $this->getContent($blueprint));

        return $path;
    }
}
