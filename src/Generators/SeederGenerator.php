<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Lartisan\Architect\ValueObjects\BlueprintData;

readonly class SeederGenerator extends AbstractGenerator
{
    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('seeder');

        return $this->replacePlaceholders($stub, [
            '{{ namespace }}' => config('architect.seeder_namespace', 'Database\\Seeders'),
            '{{ model_namespace }}' => config('architect.models_namespace', 'App\\Models'),
            '{{ class }}' => "{$blueprint->modelName}Seeder",
            '{{ model_class }}' => $blueprint->modelName,
        ]);
    }

    public function generate(BlueprintData $blueprint): string
    {
        if (! $blueprint->generateSeeder) {
            return '';
        }

        $className = "{$blueprint->modelName}Seeder";
        $path = database_path("seeders/{$className}.php");

        $this->ensureDirectoryExists($path);

        if (File::exists($path)) {
            return $path;
        }

        File::put($path, $this->getContent($blueprint));

        return $path;
    }
}
