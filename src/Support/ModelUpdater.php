<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Str;
use Lartisan\Architect\ValueObjects\BlueprintData;
use PhpParser\BuilderFactory;
use PhpParser\Comment\Doc;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class ModelUpdater
{
    private const array FOREIGN_COLUMN_SUFFIXES = ['_id', '_uuid', '_ulid'];

    public function __construct(
        private readonly RelationshipModelResolver $relationshipModelResolver,
    ) {}

    public function merge(string $existingContent, BlueprintData $blueprint): string
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $oldStatements = $parser->parse($existingContent) ?? [];
            $oldTokens = $parser->getTokens();
            $newStatements = $parser->parse($existingContent) ?? [];
        } catch (Error $exception) {
            throw new \RuntimeException('Unable to parse the existing model for merge: '.$exception->getMessage(), previous: $exception);
        }

        $namespace = $this->findNamespace($newStatements);
        $class = $this->findClass($namespace?->stmts ?? $newStatements);

        if (! $class instanceof Stmt\Class_) {
            throw new \RuntimeException('Unable to find a model class to merge.');
        }

        $this->mergeImports($namespace, $newStatements, $blueprint);
        $this->mergeTraits($class, $blueprint);
        $this->mergeFillable($class, $blueprint);
        $this->mergeRelationships($class, $blueprint);

        $content = (new Standard)->printFormatPreserving($newStatements, $oldStatements, $oldTokens);

        return $this->normalizeMergedFormatting($content);
    }

    private function normalizeMergedFormatting(string $content): string
    {
        $content = preg_replace('/;\n(?=class\s)/', ";\n\n", $content) ?? $content;
        $content = preg_replace('/\n    use [^\n]+;\n(?=    (?:\/\*\*|protected|public function))/', "$0\n", $content) ?? $content;
        $content = preg_replace('/\n    ];\n(?=    public function )/', "\n    ];\n\n", $content) ?? $content;
        $content = preg_replace('/;\n(?=    public function )/', ";\n\n", $content) ?? $content;
        $content = preg_replace('/\n    }\n(?=    public function )/', "\n    }\n\n", $content) ?? $content;

        return $content;
    }

    private function findNamespace(array $statements): ?Stmt\Namespace_
    {
        return (new NodeFinder)->findFirstInstanceOf($statements, Stmt\Namespace_::class);
    }

    private function findClass(array $statements): ?Stmt\Class_
    {
        return (new NodeFinder)->findFirstInstanceOf($statements, Stmt\Class_::class);
    }

    private function mergeImports(?Stmt\Namespace_ $namespace, array &$statements, BlueprintData $blueprint): void
    {
        $targetStatements = $namespace?->stmts ?? $statements;
        $existingImports = collect($targetStatements)
            ->filter(fn ($statement) => $statement instanceof Stmt\Use_)
            ->flatMap(fn (Stmt\Use_ $statement) => collect($statement->uses)->map(fn (Stmt\UseUse $use) => $use->name->toString()))
            ->values()
            ->all();

        $requiredImports = collect(explode("\n", $blueprint->getTraitImports()))
            ->map(fn (string $line) => trim(str_replace(['use ', ';'], '', $line)))
            ->filter()
            ->values();

        if ($this->hasRelationships($blueprint)) {
            $requiredImports->push('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo');
        }

        $importsToAdd = $requiredImports
            ->reject(fn (string $import) => in_array($import, $existingImports, true))
            ->unique()
            ->values();

        if ($importsToAdd->isEmpty()) {
            return;
        }

        $useStatements = $importsToAdd
            ->map(fn (string $import) => new Stmt\Use_([new Stmt\UseUse(new Name($import))]))
            ->all();

        $classIndex = collect($targetStatements)->search(fn ($statement) => $statement instanceof Stmt\Class_);
        $insertAt = $classIndex === false ? count($targetStatements) : $classIndex;

        array_splice($targetStatements, $insertAt, 0, $useStatements);

        if ($namespace instanceof Stmt\Namespace_) {
            $namespace->stmts = $targetStatements;

            return;
        }

        $statements = $targetStatements;
    }

    private function mergeTraits(Stmt\Class_ $class, BlueprintData $blueprint): void
    {
        $existingTraits = collect($class->stmts)
            ->filter(fn ($statement) => $statement instanceof Stmt\TraitUse)
            ->flatMap(fn (Stmt\TraitUse $statement) => collect($statement->traits)->map(fn (Name $trait) => $trait->toString()))
            ->all();

        $requiredTraits = ['HasFactory'];

        if ($blueprint->primaryKeyType === 'uuid') {
            $requiredTraits[] = 'HasUuids';
        } elseif ($blueprint->primaryKeyType === 'ulid') {
            $requiredTraits[] = 'HasUlids';
        }

        if ($blueprint->softDeletes) {
            $requiredTraits[] = 'SoftDeletes';
        }

        $traitsToAdd = array_values(array_diff($requiredTraits, $existingTraits));

        if ($traitsToAdd === []) {
            return;
        }

        $traitStatements = array_map(
            fn (string $trait) => new Stmt\TraitUse([new Name($trait)]),
            $traitsToAdd,
        );

        $insertAt = collect($class->stmts)->search(fn ($statement) => ! $statement instanceof Stmt\TraitUse);
        $insertAt = $insertAt === false ? count($class->stmts) : $insertAt;

        array_splice($class->stmts, $insertAt, 0, $traitStatements);
    }

    private function mergeFillable(Stmt\Class_ $class, BlueprintData $blueprint): void
    {
        $fillableProperty = collect($class->getProperties())
            ->first(fn (Stmt\Property $property) => $property->props[0]->name->toString() === 'fillable');

        $fillableValues = collect($blueprint->columns)
            ->map(fn ($column) => $column->name)
            ->values();

        if ($fillableProperty instanceof Stmt\Property && $fillableProperty->props[0]->default instanceof Expr\Array_) {
            $existingValues = collect($fillableProperty->props[0]->default->items)
                ->map(fn (?Expr\ArrayItem $item) => $item?->value instanceof String_ ? $item->value->value : null)
                ->filter()
                ->all();

            $valuesToAdd = $fillableValues
                ->reject(fn (string $name) => in_array($name, $existingValues, true))
                ->all();

            foreach ($valuesToAdd as $value) {
                $fillableProperty->props[0]->default->items[] = new Expr\ArrayItem(new String_($value));
            }

            return;
        }

        if ($fillableProperty instanceof Stmt\Property) {
            return;
        }

        $property = (new BuilderFactory)->property('fillable')
            ->makeProtected()
            ->setDocComment(new Doc('/** @var array<int, string> */'))
            ->setDefault(array_map(fn (string $name) => new String_($name), $fillableValues->all()))
            ->getNode();

        $insertAt = collect($class->stmts)->search(fn ($statement) => $statement instanceof Stmt\Property || $statement instanceof Stmt\ClassMethod);
        $insertAt = $insertAt === false ? count($class->stmts) : $insertAt;

        array_splice($class->stmts, $insertAt, 0, [$property]);
    }

    private function mergeRelationships(Stmt\Class_ $class, BlueprintData $blueprint): void
    {
        $existingMethods = collect($class->getMethods())
            ->map(fn (Stmt\ClassMethod $method) => $method->name->toString())
            ->all();

        $relationshipsToAdd = collect($blueprint->columns)
            ->filter(fn ($column) => $this->isForeignColumn($column->name, $column->type))
            ->map(function ($column) {
                $relationshipName = $this->extractRelationshipName($column->name);

                return [
                    'method' => $relationshipName,
                    'relatedModel' => $this->relationshipModelResolver->resolveModelName($column, $relationshipName),
                ];
            })
            ->filter(fn (array $relationship) => ! in_array($relationship['method'], $existingMethods, true))
            ->unique('method')
            ->values()
            ->all();

        foreach ($relationshipsToAdd as $relationship) {
            $class->stmts[] = new Stmt\ClassMethod(
                $relationship['method'],
                [
                    'flags' => Stmt\Class_::MODIFIER_PUBLIC,
                    'returnType' => new Identifier('BelongsTo'),
                    'stmts' => [
                        new Stmt\Return_(
                            new Expr\MethodCall(
                                new Expr\Variable('this'),
                                'belongsTo',
                                [
                                    new Node\Arg(new Expr\ClassConstFetch(new Name($relationship['relatedModel']), 'class')),
                                ],
                            ),
                        ),
                    ],
                ],
            );
        }
    }

    private function hasRelationships(BlueprintData $blueprint): bool
    {
        return collect($blueprint->columns)->contains(fn ($column) => $this->isForeignColumn($column->name, $column->type));
    }

    private function isForeignColumn(string $name, string $type): bool
    {
        return in_array($type, ['foreignId', 'foreignUuid', 'foreignUlid'], true)
            || Str::endsWith($name, self::FOREIGN_COLUMN_SUFFIXES);
    }

    private function extractRelationshipName(string $columnName): string
    {
        foreach (self::FOREIGN_COLUMN_SUFFIXES as $suffix) {
            if (Str::endsWith($columnName, $suffix)) {
                return Str::camel(Str::beforeLast($columnName, $suffix));
            }
        }

        return Str::camel($columnName);
    }
}
