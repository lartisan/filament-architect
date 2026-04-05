<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Support\SeederUpdater;
use Lartisan\Architect\ValueObjects\BlueprintData;

readonly class SeederGenerator extends AbstractGenerator
{
    private const START_MARKER = '// <architect:seed>';

    private const END_MARKER = '// </architect:seed>';

    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('seeder');

        return $this->replacePlaceholders($stub, [
            '{{ namespace }}' => GenerationPathResolver::seedersNamespace(),
            '{{ model_namespace }}' => GenerationPathResolver::modelsNamespace(),
            '{{ class }}' => "{$blueprint->modelName}Seeder",
            '{{ model_class }}' => $blueprint->modelName,
        ]);
    }

    public function preview(BlueprintData $blueprint): string
    {
        $content = parent::preview($blueprint);

        $content = str_replace([self::START_MARKER, self::END_MARKER], '', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return trim($content)."\n";
    }

    public function generate(BlueprintData $blueprint): string
    {
        if (! $blueprint->generateSeeder) {
            return '';
        }

        $className = "{$blueprint->modelName}Seeder";
        $path = GenerationPathResolver::seeder($className);

        $this->ensureDirectoryExists($path);

        if (File::exists($path)) {
            if ($blueprint->generationMode === GenerationMode::Create) {
                return $path;
            }

            if ($blueprint->generationMode->shouldMergeExistingArtifacts()) {
                $updatedContent = app(SeederUpdater::class)->merge(File::get($path), $this->getContent($blueprint));
                $this->writeFormattedFile($path, $updatedContent);

                return $path;
            }
        }

        $this->writeFormattedFile($path, $this->getContent($blueprint));

        return $path;
    }
}
