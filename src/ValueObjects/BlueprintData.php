<?php

namespace Lartisan\Architect\ValueObjects;

use Illuminate\Support\Str;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\Exceptions\InvalidBlueprintException;
use Lartisan\Architect\Support\RelationshipModelResolver;

readonly class BlueprintData
{
    /**
     * @param  array<ColumnDefinition>  $columns
     *
     * @throws InvalidBlueprintException
     */
    public function __construct(
        public string $tableName,
        public string $modelName,
        public string $primaryKeyType,
        public array $columns,
        public bool $generateFactory = true,
        public bool $generateSeeder = true,
        public bool $generateResource = true,
        public bool $runMigration = true,
        public bool $overwriteTable = false,
        public bool $softDeletes = false,
        public GenerationMode $generationMode = GenerationMode::Merge,
        public bool $allowDestructiveChanges = false,
        public bool $allowLikelyRenames = false,
        public array $meta = [],
        bool $shouldValidate = false,
    ) {
        if ($shouldValidate) {
            $this->validate();
        }
    }

    /**
     * @throws InvalidBlueprintException
     */
    public static function fromArray(array $data, bool $shouldValidate = false): self
    {
        $filteredColumns = collect($data['columns'] ?? [])
            ->filter(fn ($col) => ! empty($col['name']) && ! empty($col['type']))
            ->toArray();

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        return new self(
            tableName: $data['table_name'] ?? '',
            modelName: $data['model_name'] ?? '',
            primaryKeyType: $data['primary_key_type'] ?? 'id',
            columns: array_map(
                fn (array $col) => ColumnDefinition::fromArray($col),
                $filteredColumns
            ),
            generateFactory: (bool) ($data['gen_factory'] ?? $meta['gen_factory'] ?? false),
            generateSeeder: (bool) ($data['gen_seeder'] ?? $meta['gen_seeder'] ?? false),
            generateResource: (bool) ($data['gen_resource'] ?? $meta['gen_resource'] ?? false),
            runMigration: (bool) ($data['run_migration'] ?? false),
            overwriteTable: (bool) ($data['overwrite_table'] ?? false),
            softDeletes: (bool) ($data['soft_deletes'] ?? false),
            generationMode: GenerationMode::tryFrom((string) ($data['generation_mode'] ?? $meta['generation_mode'] ?? GenerationMode::default()->value)) ?? GenerationMode::default(),
            allowDestructiveChanges: (bool) ($data['allow_destructive_changes'] ?? $meta['allow_destructive_changes'] ?? false),
            allowLikelyRenames: (bool) ($data['allow_likely_renames'] ?? $meta['allow_likely_renames'] ?? false),
            meta: $meta,
            shouldValidate: $shouldValidate,
        );
    }

    public function toFormData(): array
    {
        return [
            'table_name' => $this->tableName,
            'model_name' => $this->modelName,
            'primary_key_type' => $this->primaryKeyType,
            'columns' => array_map(fn (ColumnDefinition $col) => $col->toArray(), $this->columns),
            'soft_deletes' => $this->softDeletes,
            'generation_mode' => $this->generationMode->value,
            'allow_destructive_changes' => $this->allowDestructiveChanges,
            'allow_likely_renames' => $this->allowLikelyRenames,
            'meta' => array_merge($this->meta, [
                'gen_factory' => $this->generateFactory,
                'gen_seeder' => $this->generateSeeder,
                'gen_resource' => $this->generateResource,
                'generation_mode' => $this->generationMode->value,
                'allow_destructive_changes' => $this->allowDestructiveChanges,
                'allow_likely_renames' => $this->allowLikelyRenames,
            ]),
        ];
    }

    public function getFillableAttributes(): string
    {
        return collect($this->columns)
            ->pluck('name')
            ->map(fn ($name) => "'$name'")
            ->implode(', ');
    }

    public function getModelTraits(): string
    {
        $traits = ['use HasFactory;'];

        if ($this->primaryKeyType === 'uuid') {
            $traits[] = 'use HasUuids;';
        } elseif ($this->primaryKeyType === 'ulid') {
            $traits[] = 'use HasUlids;';
        }

        if ($this->softDeletes) {
            $traits[] = 'use SoftDeletes;';
        }

        return implode("\n    ", $traits);
    }

    public function getTraitImports(): string
    {
        $imports = ['use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;'];

        if ($this->primaryKeyType === 'uuid') {
            $imports[] = 'use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;';
        } elseif ($this->primaryKeyType === 'ulid') {
            $imports[] = 'use Illuminate\\Database\\Eloquent\\Concerns\\HasUlids;';
        }

        if ($this->softDeletes) {
            $imports[] = 'use Illuminate\\Database\\Eloquent\\SoftDeletes;';
        }

        return implode("\n", $imports);
    }

    public function getFactoryDefinitions(): string
    {
        $definitions = [];

        foreach ($this->columns as $column) {
            $fakerMethod = $this->mapColumnToFakerMethod($column);
            $definitions[] = "'{$column->name}' => {$fakerMethod},";
        }

        return implode("\n            ", $definitions);
    }

    protected function mapColumnToFakerMethod(ColumnDefinition $column): string
    {
        $nameLower = strtolower($column->name);
        $suffixes = ['_id', '_uuid', '_ulid'];

        if (in_array($column->type, ['foreignId', 'foreignUuid', 'foreignUlid'], true) || Str::endsWith($nameLower, $suffixes)) {
            $relationshipName = $this->extractRelationshipName($nameLower);
            $modelName = app(RelationshipModelResolver::class)->resolveModelName($column, $relationshipName ?: null);
            $modelNamespace = (string) config('architect.models_namespace', config('architect.namespace', 'App\\Models'));

            return "\\{$modelNamespace}\\{$modelName}::factory()";
        }

        return match (true) {
            Str::contains($nameLower, ['email', 'mail']) => '$this->faker->unique()->safeEmail()',

            Str::contains($nameLower, ['password', 'secret']) => "\\Illuminate\\Support\\Facades\\Hash::make('password')",
            Str::contains($nameLower, ['title', 'name']) => '$this->faker->sentence()',
            Str::contains($nameLower, ['body', 'content', 'description']) => '$this->faker->paragraphs(3, true)',
            Str::contains($nameLower, ['url', 'website']) => '$this->faker->url()',
            Str::contains($nameLower, ['phone']) => '$this->faker->phoneNumber()',
            Str::contains($nameLower, ['address']) => '$this->faker->address()',
            Str::contains($nameLower, ['city']) => '$this->faker->city()',
            Str::contains($nameLower, ['country']) => '$this->faker->country()',
            Str::contains($nameLower, ['zip', 'postcode']) => '$this->faker->postcode()',
            Str::contains($nameLower, ['price', 'amount']) => '$this->faker->randomFloat(2, 10, 1000)',

            $column->type === 'string' => '$this->faker->word()',
            $column->type === 'text' => '$this->faker->paragraph()',
            $column->type === 'integer', $column->type === 'unsignedBigInteger' => '$this->faker->randomNumber()',
            $column->type === 'boolean' => '$this->faker->boolean()',
            $column->type === 'date' => '$this->faker->date()',
            $column->type === 'dateTime' => '$this->faker->dateTime()',

            $column->type === 'uuid' => '\\Illuminate\\Support\\Str::uuid()',
            $column->type === 'ulid' => '\\Illuminate\\Support\\Str::ulid()',

            default => '$this->faker->word()',
        };
    }

    private function extractRelationshipName(string $columnName): ?string
    {
        foreach (['_id', '_uuid', '_ulid'] as $suffix) {
            if (Str::endsWith($columnName, $suffix)) {
                return Str::camel(Str::beforeLast($columnName, $suffix));
            }
        }

        return null;
    }

    private function validate(): void
    {
        if (empty($this->tableName)) {
            throw new InvalidBlueprintException(__('Table name cannot be empty.'));
        }

        $columnNames = array_map(fn (ColumnDefinition $col) => $col->name, $this->columns);

        $duplicates = array_unique(array_diff_assoc($columnNames, array_unique($columnNames)));

        if (! empty($duplicates)) {
            throw InvalidBlueprintException::duplicateColumns($duplicates);
        }
    }
}
