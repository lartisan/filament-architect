<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Support\ModelUpdater;
use Lartisan\Architect\ValueObjects\BlueprintData;

readonly class ModelGenerator extends AbstractGenerator
{
    public function generate(BlueprintData $blueprint): string
    {
        $path = GenerationPathResolver::model($blueprint->modelName);

        $this->ensureDirectoryExists($path);

        if (File::exists($path)) {
            if ($blueprint->generationMode === GenerationMode::Create) {
                return $path;
            }

            if ($blueprint->generationMode->shouldMergeExistingArtifacts()) {
                $updatedContent = app(ModelUpdater::class)->merge(File::get($path), $blueprint);
                $this->writeFormattedFile($path, $updatedContent);

                return $path;
            }
        }

        $this->writeFormattedFile($path, $this->getContent($blueprint));

        return $path;
    }

    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('model');

        return $this->replacePlaceholders($stub, [
            '{{ namespace }}' => GenerationPathResolver::modelsNamespace(),
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
