<?php

namespace Lartisan\Architect\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class FilamentResourceUpdater
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
            throw new \RuntimeException('Unable to parse the existing resource for merge: '.$exception->getMessage(), previous: $exception);
        }

        $existingNamespace = $this->findNamespace($mutableStatements);
        $generatedNamespace = $this->findNamespace($generatedStatements);
        $existingClass = $this->findClass($existingNamespace?->stmts ?? $mutableStatements);
        $generatedClass = $this->findClass($generatedNamespace?->stmts ?? $generatedStatements);

        if (! $existingClass instanceof Stmt\Class_ || ! $generatedClass instanceof Stmt\Class_) {
            throw new \RuntimeException('Unable to find a resource class to merge.');
        }

        $this->mergeImports($existingNamespace, $mutableStatements, $generatedNamespace?->stmts ?? $generatedStatements);
        $this->mergeComponentsMethod($existingClass, $generatedClass, 'form', 'components');
        $this->mergeComponentsMethod($existingClass, $generatedClass, 'infolist', 'components');
        $this->mergeComponentsMethod($existingClass, $generatedClass, 'table', 'columns');
        $this->mergeComponentsMethod($existingClass, $generatedClass, 'table', 'filters');
        $this->mergeBulkActions($existingClass, $generatedClass);
        $this->mergeMethodIfMissing($existingClass, $generatedClass, 'getEloquentQuery');

        $content = (new Standard)->printFormatPreserving($mutableStatements, $existingStatements, $existingTokens);

        return $this->normalizeMergedFormatting($content);
    }

    /**
     * Merge a v4 thin resource file.
     *
     * Only syncs imports and adds getEloquentQuery() if missing.
     * Component arrays live in separate Form/Infolist/Table files
     * and are merged by mergeSchemaFile() / mergeTableFile().
     */
    public function mergeThinResource(string $existingContent, string $generatedContent): string
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $existingStatements = $parser->parse($existingContent) ?? [];
            $existingTokens = $parser->getTokens();
            $mutableStatements = $parser->parse($existingContent) ?? [];
            $generatedStatements = $parser->parse($generatedContent) ?? [];
        } catch (Error $exception) {
            throw new \RuntimeException('Unable to parse the existing resource for merge: '.$exception->getMessage(), previous: $exception);
        }

        $existingNamespace = $this->findNamespace($mutableStatements);
        $generatedNamespace = $this->findNamespace($generatedStatements);
        $existingClass = $this->findClass($existingNamespace?->stmts ?? $mutableStatements);
        $generatedClass = $this->findClass($generatedNamespace?->stmts ?? $generatedStatements);

        if (! $existingClass instanceof Stmt\Class_ || ! $generatedClass instanceof Stmt\Class_) {
            throw new \RuntimeException('Unable to find a resource class to merge.');
        }

        $this->mergeImports($existingNamespace, $mutableStatements, $generatedNamespace?->stmts ?? $generatedStatements);
        $this->mergeMethodIfMissing($existingClass, $generatedClass, 'getEloquentQuery');

        $content = (new Standard)->printFormatPreserving($mutableStatements, $existingStatements, $existingTokens);

        return $this->normalizeMergedFormatting($content);
    }

    /**
     * Merge a v4 Schema file (UserForm or UserInfolist).
     *
     * Targets the configure() method and merges ->components([]).
     *
     * @param  string  $context  'form' or 'infolist' — determines which component classes are managed
     */
    public function mergeSchemaFile(string $existingContent, string $generatedContent, string $context = 'form'): string
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $existingStatements = $parser->parse($existingContent) ?? [];
            $existingTokens = $parser->getTokens();
            $mutableStatements = $parser->parse($existingContent) ?? [];
            $generatedStatements = $parser->parse($generatedContent) ?? [];
        } catch (Error $exception) {
            throw new \RuntimeException('Unable to parse the existing schema file for merge: '.$exception->getMessage(), previous: $exception);
        }

        $existingNamespace = $this->findNamespace($mutableStatements);
        $generatedNamespace = $this->findNamespace($generatedStatements);
        $existingClass = $this->findClass($existingNamespace?->stmts ?? $mutableStatements);
        $generatedClass = $this->findClass($generatedNamespace?->stmts ?? $generatedStatements);

        if (! $existingClass instanceof Stmt\Class_ || ! $generatedClass instanceof Stmt\Class_) {
            throw new \RuntimeException('Unable to find a schema class to merge.');
        }

        $managedClasses = $context === 'infolist'
            ? ['IconEntry', 'TextEntry', 'KeyValueEntry']
            : ['Select', 'Toggle', 'DatePicker', 'DateTimePicker', 'Textarea', 'KeyValue', 'TextInput'];

        $this->mergeImports($existingNamespace, $mutableStatements, $generatedNamespace?->stmts ?? $generatedStatements);
        $this->mergeComponentsMethod($existingClass, $generatedClass, 'configure', 'components', $managedClasses);

        $content = (new Standard)->printFormatPreserving($mutableStatements, $existingStatements, $existingTokens);

        return $this->normalizeMergedFormatting($content);
    }

    /**
     * Merge a v4 Table file (UsersTable).
     *
     * Targets the configure() method and merges ->columns(), ->filters() and bulk actions.
     */
    public function mergeTableFile(string $existingContent, string $generatedContent): string
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $existingStatements = $parser->parse($existingContent) ?? [];
            $existingTokens = $parser->getTokens();
            $mutableStatements = $parser->parse($existingContent) ?? [];
            $generatedStatements = $parser->parse($generatedContent) ?? [];
        } catch (Error $exception) {
            throw new \RuntimeException('Unable to parse the existing table file for merge: '.$exception->getMessage(), previous: $exception);
        }

        $existingNamespace = $this->findNamespace($mutableStatements);
        $generatedNamespace = $this->findNamespace($generatedStatements);
        $existingClass = $this->findClass($existingNamespace?->stmts ?? $mutableStatements);
        $generatedClass = $this->findClass($generatedNamespace?->stmts ?? $generatedStatements);

        if (! $existingClass instanceof Stmt\Class_ || ! $generatedClass instanceof Stmt\Class_) {
            throw new \RuntimeException('Unable to find a table class to merge.');
        }

        $this->mergeImports($existingNamespace, $mutableStatements, $generatedNamespace?->stmts ?? $generatedStatements);
        $this->mergeComponentsMethod($existingClass, $generatedClass, 'configure', 'columns', ['TextColumn', 'IconColumn']);
        $this->mergeComponentsMethod($existingClass, $generatedClass, 'configure', 'filters', ['TrashedFilter']);
        $this->mergeBulkActions($existingClass, $generatedClass, 'configure');

        $content = (new Standard)->printFormatPreserving($mutableStatements, $existingStatements, $existingTokens);

        return $this->normalizeMergedFormatting($content);
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

    private function mergeComponentsMethod(Stmt\Class_ $existingClass, Stmt\Class_ $generatedClass, string $methodName, string $callName, ?array $managedClasses = null): void
    {
        $existingMethod = $existingClass->getMethod($methodName);
        $generatedMethod = $generatedClass->getMethod($methodName);

        if (! $generatedMethod instanceof Stmt\ClassMethod) {
            return;
        }

        if (! $existingMethod instanceof Stmt\ClassMethod) {
            $existingClass->stmts[] = $generatedMethod;

            return;
        }

        $existingArray = $this->findMethodCallArray($existingMethod, $callName);
        $generatedArray = $this->findMethodCallArray($generatedMethod, $callName);

        if (! $existingArray instanceof Expr\Array_ || ! $generatedArray instanceof Expr\Array_) {
            return;
        }

        $existingArray->items = $this->mergeArrayItemsInGeneratedOrder(
            $existingArray,
            $generatedArray,
            $managedClasses ?? $this->managedClassNamesFor($methodName, $callName),
        );
    }

    private function mergeBulkActions(Stmt\Class_ $existingClass, Stmt\Class_ $generatedClass, string $methodName = 'table'): void
    {
        $existingMethod = $existingClass->getMethod($methodName);
        $generatedMethod = $generatedClass->getMethod($methodName);

        if (! $generatedMethod instanceof Stmt\ClassMethod) {
            return;
        }

        if (! $existingMethod instanceof Stmt\ClassMethod) {
            $existingClass->stmts[] = $generatedMethod;

            return;
        }

        $existingToolbarArray = $this->findMethodCallArray($existingMethod, 'toolbarActions');
        $generatedToolbarArray = $this->findMethodCallArray($generatedMethod, 'toolbarActions');

        // Nothing to merge if the generated method has no toolbarActions().
        if (! $generatedToolbarArray instanceof Expr\Array_) {
            return;
        }

        // Existing table() has no toolbarActions() yet — inject the entire generated call.
        if (! $existingToolbarArray instanceof Expr\Array_) {
            $this->appendGeneratedToolbarActionsCall($existingMethod, $generatedMethod);

            return;
        }

        $existingBulkActionGroup = $this->findBulkActionGroupArray($existingToolbarArray);
        $generatedBulkActionGroup = $this->findBulkActionGroupArray($generatedToolbarArray);

        if (! $generatedBulkActionGroup instanceof Expr\Array_) {
            return;
        }

        if (! $existingBulkActionGroup instanceof Expr\Array_) {
            foreach ($generatedToolbarArray->items as $item) {
                if ($item instanceof Expr\ArrayItem) {
                    $existingToolbarArray->items[] = $item;
                }
            }

            return;
        }

        $existingBulkActionGroup->items = $this->mergeArrayItemsInGeneratedOrder(
            $existingBulkActionGroup,
            $generatedBulkActionGroup,
            ['DeleteBulkAction', 'ForceDeleteBulkAction', 'RestoreBulkAction'],
        );
    }

    private function mergeArrayItemsInGeneratedOrder(Expr\Array_ $existingArray, Expr\Array_ $generatedArray, array $managedClassNames = []): array
    {
        $existingBySignature = [];
        $customExistingItems = [];
        $generatedSignatures = collect($generatedArray->items)
            ->map(fn (?Expr\ArrayItem $item) => $item instanceof Expr\ArrayItem ? $this->expressionSignature($item->value) : null)
            ->filter()
            ->values()
            ->all();

        foreach ($existingArray->items as $item) {
            if (! $item instanceof Expr\ArrayItem) {
                continue;
            }

            $signature = $this->expressionSignature($item->value);

            if ($signature !== null && in_array($signature, $generatedSignatures, true)) {
                if (! array_key_exists($signature, $existingBySignature)) {
                    $existingBySignature[$signature] = $item;
                }

                continue;
            }

            if ($signature !== null && $this->isManagedSignature($signature, $managedClassNames)) {
                continue;
            }

            $customExistingItems[] = $item;
        }

        $mergedItems = [];

        foreach ($generatedArray->items as $item) {
            if (! $item instanceof Expr\ArrayItem) {
                continue;
            }

            $signature = $this->expressionSignature($item->value);

            if ($signature !== null && isset($existingBySignature[$signature])) {
                $mergedItems[] = $existingBySignature[$signature];

                continue;
            }

            $mergedItems[] = $item;
        }

        return array_merge($mergedItems, $customExistingItems);
    }

    private function managedClassNamesFor(string $methodName, string $callName): array
    {
        return match ([$methodName, $callName]) {
            ['form', 'components'] => ['Select', 'Toggle', 'DatePicker', 'DateTimePicker', 'Textarea', 'KeyValue', 'TextInput'],
            ['infolist', 'components'] => ['IconEntry', 'TextEntry', 'KeyValueEntry'],
            ['table', 'columns'] => ['TextColumn', 'IconColumn'],
            ['table', 'filters'] => ['TrashedFilter'],
            default => [],
        };
    }

    private function isManagedSignature(string $signature, array $managedClassNames): bool
    {
        if ($managedClassNames === []) {
            return false;
        }

        $className = explode('::', $signature)[0] ?? null;

        return is_string($className) && in_array($className, $managedClassNames, true);
    }

    private function mergeMethodIfMissing(Stmt\Class_ $existingClass, Stmt\Class_ $generatedClass, string $methodName): void
    {
        if ($existingClass->getMethod($methodName) instanceof Stmt\ClassMethod) {
            return;
        }

        $generatedMethod = $generatedClass->getMethod($methodName);

        if ($generatedMethod instanceof Stmt\ClassMethod) {
            $existingClass->stmts[] = $generatedMethod;
        }
    }

    /**
     * When the existing table() method has no toolbarActions() call yet, find the
     * toolbarActions() node in the generated method and append it to the outermost
     * fluent chain in the existing method's return statement.
     */
    private function appendGeneratedToolbarActionsCall(Stmt\ClassMethod $existingMethod, Stmt\ClassMethod $generatedMethod): void
    {
        /** @var Expr\MethodCall|null $generatedCall */
        $generatedCall = (new NodeFinder)->findFirst($generatedMethod->stmts ?? [], function ($node) {
            return $node instanceof Expr\MethodCall
                && $node->name instanceof Identifier
                && $node->name->toString() === 'toolbarActions'
                && isset($node->args[0])
                && $node->args[0]->value instanceof Expr\Array_;
        });

        if (! $generatedCall instanceof Expr\MethodCall) {
            return;
        }

        foreach ($existingMethod->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Stmt\Return_ || ! $stmt->expr instanceof Expr) {
                continue;
            }

            $stmt->expr = new Expr\MethodCall(
                $stmt->expr,
                new Identifier('toolbarActions'),
                $generatedCall->args,
            );

            return;
        }
    }

    private function findMethodCallArray(Stmt\ClassMethod $method, string $callName): ?Expr\Array_
    {
        $call = (new NodeFinder)->findFirst($method->stmts ?? [], function ($node) use ($callName) {
            return $node instanceof Expr\MethodCall
                && $node->name instanceof Identifier
                && $node->name->toString() === $callName
                && isset($node->args[0])
                && $node->args[0]->value instanceof Expr\Array_;
        });

        return $call instanceof Expr\MethodCall ? $call->args[0]->value : null;
    }

    private function findBulkActionGroupArray(Expr\Array_ $toolbarActions): ?Expr\Array_
    {
        foreach ($toolbarActions->items as $item) {
            if (! $item instanceof Expr\ArrayItem) {
                continue;
            }

            $value = $item->value;

            if (! $value instanceof Expr\StaticCall || ! $value->class instanceof Node\Name) {
                continue;
            }

            if ($value->class->toString() !== '\\Filament\\Actions\\BulkActionGroup' && $value->class->toString() !== 'Filament\\Actions\\BulkActionGroup') {
                continue;
            }

            return isset($value->args[0]) && $value->args[0]->value instanceof Expr\Array_
                ? $value->args[0]->value
                : null;
        }

        return null;
    }

    private function expressionSignature(Expr $expression): ?string
    {
        if ($expression instanceof Expr\MethodCall) {
            return $this->expressionSignature($expression->var);
        }

        if (! $expression instanceof Expr\StaticCall || ! $expression->class instanceof Node\Name) {
            return null;
        }

        $methodName = $expression->name instanceof Identifier
            ? $expression->name->toString()
            : (string) $expression->name;

        $signature = $this->canonicalClassName($expression->class).'::'.$methodName;

        if (isset($expression->args[0]) && $expression->args[0]->value instanceof String_) {
            $signature .= ':'.$expression->args[0]->value->value;
        }

        return $signature;
    }

    private function normalizeMergedFormatting(string $content): string
    {
        $content = preg_replace('/return (\$schema)->components\(\[/', "return $1\n            ->components([", $content) ?? $content;
        $content = preg_replace('/return (\$table)->columns\(\[/', "return $1\n            ->columns([", $content) ?? $content;
        $content = str_replace('])->filters([', "])\n            ->filters([", $content);
        $content = str_replace('])->recordActions([', "])\n            ->recordActions([", $content);
        $content = str_replace('])->toolbarActions([', "])\n            ->toolbarActions([", $content);
        $content = preg_replace('/return (parent::getEloquentQuery\(\))->withoutGlobalScopes\(\[/', "return $1\n            ->withoutGlobalScopes([", $content) ?? $content;
        $content = $this->normalizeGetPagesFormatting($content);
        $content = $this->normalizeClassMemberSpacing($content);

        foreach (['->components([', '->columns([', '->filters([', '->recordActions([', '->toolbarActions([', 'BulkActionGroup::make(['] as $needle) {
            $content = $this->normalizeArrayArgumentFormatting($content, $needle);
        }

        return $content;
    }

    private function normalizeClassMemberSpacing(string $content): string
    {
        $content = preg_replace('/;\n(?=    (?:protected|public) static )/', ";\n\n", $content) ?? $content;
        $content = preg_replace('/\n    }\n(?=    public static function )/', "\n    }\n\n", $content) ?? $content;

        return $content;
    }

    private function canonicalClassName(Node\Name $className): string
    {
        return $className->getLast();
    }

    private function normalizeArrayArgumentFormatting(string $content, string $needle): string
    {
        $offset = 0;

        while (($position = strpos($content, $needle, $offset)) !== false) {
            $arrayStart = $position + strlen($needle);
            $arrayEnd = $this->findMatchingBracket($content, $arrayStart - 1);

            if ($arrayEnd === null) {
                break;
            }

            $body = substr($content, $arrayStart, $arrayEnd - $arrayStart);
            $items = $this->splitTopLevelArrayItems($body);

            if ($items === []) {
                $offset = $arrayEnd + 1;

                continue;
            }

            $baseIndent = $this->indentationAt($content, $position);
            $itemIndent = $baseIndent.'    ';
            $normalizedItems = collect($items)
                ->map(fn (string $item) => $this->normalizeItemIndentation($item, $itemIndent, splitFluentChains: true))
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

    private function normalizeItemIndentation(string $item, string $indent, bool $splitFluentChains = false): string
    {
        $source = trim($item);

        if ($splitFluentChains) {
            $source = $this->splitFluentChainLines($source, $indent);
        }

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
            ->map(function (string $line, int $index) use ($indent, $minIndent) {
                $normalizedLine = $minIndent > 0 ? preg_replace('/^\s{0,'.$minIndent.'}/', '', $line) : $line;
                $lineIndent = $index === 0 ? $indent : $indent.'    ';

                return trim($line) === '' ? $lineIndent : $lineIndent.ltrim((string) $normalizedLine, "\t ");
            })
            ->implode("\n");
    }

    private function splitFluentChainLines(string $item, string $indent): string
    {
        $segments = [];
        $buffer = '';
        $parenDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;
        $quote = null;
        $escapeNext = false;
        $length = strlen($item);

        for ($index = 0; $index < $length; $index++) {
            $character = $item[$index];
            $nextCharacter = $index + 1 < $length ? $item[$index + 1] : null;

            if ($quote !== null) {
                $buffer .= $character;

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
                $buffer .= $character;

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

            if ($character === '-' && $nextCharacter === '>' && $parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $segments[] = rtrim($buffer);
                $buffer = '->';
                $index++;

                continue;
            }

            $buffer .= $character;
        }

        if (trim($buffer) !== '') {
            $segments[] = rtrim($buffer);
        }

        if (count($segments) <= 1) {
            return trim($item);
        }

        return collect($segments)
            ->map(function (string $segment, int $index) use ($indent) {
                $lineIndent = $index === 0 ? '' : $indent.'    ';

                return $lineIndent.trim($segment);
            })
            ->implode("\n");
    }

    private function normalizeGetPagesFormatting(string $content): string
    {
        $needle = 'return [';
        $offset = 0;

        while (($position = strpos($content, $needle, $offset)) !== false) {
            $methodStart = strrpos(substr($content, 0, $position), 'public static function getPages(): array');

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
}
