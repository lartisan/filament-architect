<?php

namespace Lartisan\Architect\Support;

use RuntimeException;

class SeederUpdater
{
    private const START_MARKER = '// <architect:seed>';

    private const END_MARKER = '// </architect:seed>';

    public function merge(string $existingContent, string $generatedContent): string
    {
        $generatedRegion = $this->extractManagedRegion($generatedContent);

        if (str_contains($existingContent, self::START_MARKER) && str_contains($existingContent, self::END_MARKER)) {
            return preg_replace(
                '/'.preg_quote(self::START_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'/s',
                $generatedRegion,
                $existingContent,
                1,
            ) ?? $existingContent;
        }

        if (preg_match('/public function run\(\): void\s*\{/', $existingContent, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('Unable to find a seeder run() method to merge.');
        }

        $methodSignature = (string) $matches[0][0];
        $methodOffset = (int) $matches[0][1];
        $methodStart = $methodOffset + strlen($methodSignature);
        $methodEnd = $this->findMatchingBracePosition($existingContent, $methodStart - 1);

        if ($methodEnd === null) {
            throw new RuntimeException('Unable to determine the end of the seeder run() method.');
        }

        $indent = $this->detectIndentation($existingContent, $methodEnd);
        $region = preg_replace('/^/m', $indent, trim($generatedRegion)) ?? trim($generatedRegion);

        return substr($existingContent, 0, $methodEnd)
            ."\n\n{$region}\n"
            .substr($existingContent, $methodEnd);
    }

    private function extractManagedRegion(string $content): string
    {
        if (preg_match('/'.preg_quote(self::START_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'/s', $content, $matches) !== 1) {
            throw new RuntimeException('Unable to find a managed seeder region in the generated content.');
        }

        return trim($matches[0]);
    }

    private function findMatchingBracePosition(string $content, int $openingBracePosition): ?int
    {
        $depth = 0;
        $length = strlen($content);

        for ($position = $openingBracePosition; $position < $length; $position++) {
            if ($content[$position] === '{') {
                $depth++;
            }

            if ($content[$position] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return null;
    }

    private function detectIndentation(string $content, int $closingBracePosition): string
    {
        $lineStart = strrpos(substr($content, 0, $closingBracePosition), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $line = substr($content, $lineStart, $closingBracePosition - $lineStart);

        preg_match('/^\s*/', $line, $matches);

        return ($matches[0] ?? '').'    ';
    }
}
