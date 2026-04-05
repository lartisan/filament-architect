<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Facades\File;

class GeneratedCodeFormatter
{
    public function format(string $path): void
    {
        if (! config('architect.format_generated_files', true) || ! File::exists($path)) {
            return;
        }

        $formatter = (string) config('architect.formatter', 'pint_if_available');

        if (! in_array($formatter, ['pint', 'pint_if_available'], true)) {
            return;
        }

        $binary = $this->findPintBinary();

        if ($binary === null) {
            return;
        }

        @exec(sprintf(
            '%s %s %s >/dev/null 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($binary),
            escapeshellarg($path),
        ));
    }

    private function findPintBinary(): ?string
    {
        $candidates = [
            base_path('vendor/bin/pint'),
            base_path('vendor/bin/pint.bat'),
        ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
