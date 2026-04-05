<?php

namespace Lartisan\Architect\Support;

use PhpParser\Error;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class FactoryUpdater
{
    public function merge(string $existingContent, string $generatedContent): string
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $existingStatements = $parser->parse($existingContent) ?? [];
            $existingTokens = $parser->getTokens();
            $mutableStatements = $parser->parse($existingContent) ?? [];
            $generatedStatements = $parser->parse($generatedContent) ?? [];
        } catch (Error $exception) {
            throw new \RuntimeException('Unable to parse the existing factory for merge: '.$exception->getMessage(), previous: $exception);
        }

        $existingNamespace = $this->findNamespace($mutableStatements);
        $generatedNamespace = $this->findNamespace($generatedStatements);
        $existingClass = $this->findClass($existingNamespace?->stmts ?? $mutableStatements);
        $generatedClass = $this->findClass($generatedNamespace?->stmts ?? $generatedStatements);

        if (! $existingClass instanceof Stmt\Class_ || ! $generatedClass instanceof Stmt\Class_) {
            throw new \RuntimeException('Unable to find a factory class to merge.');
        }

        $this->mergeImports($existingNamespace, $mutableStatements, $generatedNamespace?->stmts ?? $generatedStatements);
        $this->mergeDefinitionMethod($existingClass, $generatedClass);

        $content = (new Standard)->printFormatPreserving($mutableStatements, $existingStatements, $existingTokens);

        return $this->normalizeMergedFormatting($content);
    }

    private function normalizeMergedFormatting(string $content): string
    {
        $content = $this->normalizeDefinitionMethodBlock($content);
        $content = preg_replace('/;\n(?=class\s)/', ";\n\n", $content) ?? $content;
        $content = preg_replace('/;\n(?=    public function )/', ";\n\n", $content) ?? $content;
        $content = preg_replace('/\n    }\n(?=    public function )/', "\n    }\n\n", $content) ?? $content;
        $content = $this->normalizeDefinitionArrayFormatting($content);

        return $content;
    }

    private function normalizeDefinitionMethodBlock(string $content): string
    {
        return preg_replace_callback('/public function definition\(\): array\s*\{\s*return \[(.*?)\];\s*\}/s', function (array $matches) {
            $items = $this->splitTopLevelArrayItems($matches[1] ?? '');
            $formattedItems = collect($items)
                ->map(fn (string $item) => $this->normalizeItemIndentation($item, '            '))
                ->implode(",\n");

            return "public function definition(): array\n    {\n        return [\n{$formattedItems}\n        ];\n    }";
        }, $content) ?? $content;
    }

    private function normalizeDefinitionArrayFormatting(string $content): string
    {
        $needle = 'return [';
        $methodNeedle = 'public function definition(): array';
        $offset = 0;

        while (($position = strpos($content, $needle, $offset)) !== false) {
            $methodStart = strrpos(substr($content, 0, $position), $methodNeedle);

            if ($methodStart === false || $methodStart > $position) {
                $offset = $position + strlen($needle);

                continue;
            }

            $arrayStart = $position + strlen($needle);
            $arrayEnd = $this->findMatchingBracket($content, $arrayStart - 1);

            if ($arrayEnd === null) {
                break;
            }

            $body = substr($content, $arrayStart, $arrayEnd - $arrayStart);
            $items = $this->splitTopLevelArrayItems($body);
            $baseIndent = $this->indentationAt($content, $position);
            $itemIndent = $baseIndent.'    ';
            $normalizedItems = collect($items)
                ->map(fn (string $item) => $this->normalizeItemIndentation($item, $itemIndent))
                ->implode(",\n");

            $replacement = "\n{$normalizedItems}\n{$baseIndent}";
            $content = substr($content, 0, $arrayStart).$replacement.substr($content, $arrayEnd);
            $offset = $arrayStart + strlen($replacement) + 1;
        }

        return $content;
    }

    private function splitTopLevelArrayItems(string $body): array
    {
        $items = [];
        $buffer = '';
        $parenDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;
        $quote = null;
        $escapeNext = false;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];
            $buffer .= $character;

            if ($quote !== null) {
                if ($escapeNext) {
                    $escapeNext = false;

                    continue;
                }

                if ($character === '\\') {
                    $escapeNext = true;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;

                continue;
            }

            match ($character) {
                '(' => $parenDepth++,
                ')' => $parenDepth--,
                '[' => $bracketDepth++,
                ']' => $bracketDepth--,
                '{' => $braceDepth++,
                '}' => $braceDepth--,
                default => null,
            };

            if ($character === ',' && $parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $items[] = rtrim(substr($buffer, 0, -1));
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $items[] = rtrim($buffer, ",\n\r\t ");
        }

        return array_values(array_filter(array_map('trim', $items), fn (string $item) => $item !== ''));
    }

    private function normalizeItemIndentation(string $item, string $indent): string
    {
        $source = trim($item);
        $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];

        if ($lines === []) {
            return $indent.$source;
        }

        $minIndent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            preg_match('/^\s*/', $line, $matches);
            $lineIndent = strlen($matches[0] ?? '');
            $minIndent = $minIndent === null ? $lineIndent : min($minIndent, $lineIndent);
        }

        $minIndent ??= 0;

        return collect($lines)
            ->map(function (string $line) use ($indent, $minIndent) {
                $normalizedLine = $minIndent > 0 ? preg_replace('/^\s{0,'.$minIndent.'}/', '', $line) : $line;

                return trim($line) === '' ? $indent : $indent.ltrim((string) $normalizedLine, "\t ");
            })
            ->implode("\n");
    }

    private function indentationAt(string $content, int $position): string
    {
        $lineStart = strrpos(substr($content, 0, $position), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $line = substr($content, $lineStart, $position - $lineStart);

        preg_match('/^\s*/', $line, $matches);

        return $matches[0] ?? '';
    }

    private function findMatchingBracket(string $content, int $openingBracketPosition): ?int
    {
        $depth = 0;
        $quote = null;
        $escapeNext = false;
        $length = strlen($content);

        for ($position = $openingBracketPosition; $position < $length; $position++) {
            $character = $content[$position];

            if ($quote !== null) {
                if ($escapeNext) {
                    $escapeNext = false;

                    continue;
                }

                if ($character === '\\') {
                    $escapeNext = true;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === '[') {
                $depth++;
            }

            if ($character === ']') {
                $depth--;

                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return null;
    }

    private function findNamespace(array $statements): ?Stmt\Namespace_
    {
        return (new NodeFinder)->findFirstInstanceOf($statements, Stmt\Namespace_::class);
    }

    private function findClass(array $statements): ?Stmt\Class_
    {
        return (new NodeFinder)->findFirstInstanceOf($statements, Stmt\Class_::class);
    }

    private function mergeImports(?Stmt\Namespace_ $namespace, array &$statements, array $generatedStatements): void
    {
        $targetStatements = $namespace?->stmts ?? $statements;

        $existingImports = collect($targetStatements)
            ->filter(fn ($statement) => $statement instanceof Stmt\Use_)
            ->flatMap(fn (Stmt\Use_ $statement) => collect($statement->uses)->map(fn (Stmt\UseUse $use) => $use->name->toString()))
            ->all();

        $importsToAdd = collect($generatedStatements)
            ->filter(fn ($statement) => $statement instanceof Stmt\Use_)
            ->reject(fn (Stmt\Use_ $statement) => collect($statement->uses)->every(fn (Stmt\UseUse $use) => in_array($use->name->toString(), $existingImports, true)))
            ->values()
            ->all();

        if ($importsToAdd === []) {
            return;
        }

        $classIndex = collect($targetStatements)->search(fn ($statement) => $statement instanceof Stmt\Class_);
        $insertAt = $classIndex === false ? count($targetStatements) : $classIndex;
        array_splice($targetStatements, $insertAt, 0, $importsToAdd);

        if ($namespace instanceof Stmt\Namespace_) {
            $namespace->stmts = $targetStatements;

            return;
        }

        $statements = $targetStatements;
    }

    private function mergeDefinitionMethod(Stmt\Class_ $existingClass, Stmt\Class_ $generatedClass): void
    {
        $existingMethod = $existingClass->getMethod('definition');
        $generatedMethod = $generatedClass->getMethod('definition');

        if (! $generatedMethod instanceof Stmt\ClassMethod) {
            return;
        }

        if (! $existingMethod instanceof Stmt\ClassMethod) {
            $existingClass->stmts[] = $generatedMethod;

            return;
        }

        $existingArray = $this->findReturnedArray($existingMethod);
        $generatedArray = $this->findReturnedArray($generatedMethod);

        if (! $existingArray instanceof Expr\Array_ || ! $generatedArray instanceof Expr\Array_) {
            return;
        }

        $existingKeys = collect($existingArray->items)
            ->map(fn (?Expr\ArrayItem $item) => $item?->key instanceof String_ ? $item->key->value : null)
            ->filter()
            ->all();

        foreach ($generatedArray->items as $item) {
            if (! $item instanceof Expr\ArrayItem || ! $item->key instanceof String_) {
                continue;
            }

            if (in_array($item->key->value, $existingKeys, true)) {
                continue;
            }

            $existingArray->items[] = $item;
        }
    }

    private function findReturnedArray(Stmt\ClassMethod $method): ?Expr\Array_
    {
        $return = (new NodeFinder)->findFirst($method->stmts ?? [], fn ($node) => $node instanceof Stmt\Return_ && $node->expr instanceof Expr\Array_);

        return $return instanceof Stmt\Return_ ? $return->expr : null;
    }
}
