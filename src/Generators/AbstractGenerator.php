<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Support\Concerns\Resolvable;
use Lartisan\Architect\ValueObjects\BlueprintData;

abstract readonly class AbstractGenerator
{
    // use Resolvable;

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
}
