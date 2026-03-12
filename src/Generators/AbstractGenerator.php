<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Support\GeneratedCodeFormatter;
use Lartisan\Architect\ValueObjects\BlueprintData;

abstract readonly class AbstractGenerator
{
    abstract public function generate(BlueprintData $blueprint): string;

    abstract protected function getContent(BlueprintData $blueprint): string;

    public static function make(...$arguments): static
    {
        return new static($arguments);
    }

    public function preview(BlueprintData $blueprint): string
    {
        return $this->getContent($blueprint);
    }

    protected function getStub(string $name): string
    {
        return File::get(__DIR__."/../../stubs/{$name}.stub");
    }

    protected function replacePlaceholders(string $stub, array $replacements): string
    {
        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $stub
        );
    }

    protected function ensureDirectoryExists(string $path): void
    {
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    protected function writeFormattedFile(string $path, string $content): void
    {
        File::put($path, $content);

        app(GeneratedCodeFormatter::class)->format($path);
    }
}
