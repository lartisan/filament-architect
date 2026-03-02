<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lartisan\Architect\ValueObjects\BlueprintData;

readonly class ModelGenerator extends AbstractGenerator
{
    public function generate(BlueprintData $blueprint): string
    {
        $path = app_path("Models/{$blueprint->modelName}.php");

        $this->ensureDirectoryExists($path);

        File::put($path, $this->getContent($blueprint));

        return $path;
    }

    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('model');

        return $this->replacePlaceholders($stub, [
            '{{ namespace }}' => config('architect.namespace', 'App\\Models'),
            '{{ imports }}' => $blueprint->getTraitImports().$this->getRelationshipImports($blueprint),
            '{{ class }}' => $blueprint->modelName,
            '{{ traits }}' => $blueprint->getModelTraits(),
            '{{ fillable }}' => $blueprint->getFillableAttributes(),
            '{{ relationships }}' => $this->generateRelationships($blueprint),
        ]);
    }

    private function getRelationshipImports(BlueprintData $blueprint): string
    {
        $hasRelationships = collect($blueprint->columns)->contains(function ($column) {
            return in_array($column->type, ['foreignId', 'foreignUuid', 'foreignUlid']) ||
                   Str::endsWith($column->name, ['_id', '_uuid', '_ulid']);
        });

        return $hasRelationships
            ? "\nuse Illuminate\Database\Eloquent\Relations\BelongsTo;"
            : '';
    }

    private function extractRelationshipName(string $columnName): ?string
    {
        $suffixes = ['_id', '_uuid', '_ulid'];
        foreach ($suffixes as $suffix) {
            if (Str::endsWith($columnName, $suffix)) {
                return Str::camel(Str::beforeLast($columnName, $suffix));
            }
        }
        return null;
    }

    private function generateRelationships(BlueprintData $blueprint): string
    {
        $relationships = [];

        foreach ($blueprint->columns as $column) {
            $relationshipName = null;

            if (in_array($column->type, ['foreignId', 'foreignUuid', 'foreignUlid']) ||
                Str::endsWith($column->name, ['_id', '_uuid', '_ulid'])) {
                $relationshipName = $this->extractRelationshipName($column->name);
            }

            if ($relationshipName) {
                $relatedModelClass = Str::studly($relationshipName);

                $relationships[] = <<<PHP
    public function {$relationshipName}(): BelongsTo
    {
        return \$this->belongsTo({$relatedModelClass}::class);
    }
PHP;
            }
        }

        return implode("\n\n", $relationships);
    }
}
