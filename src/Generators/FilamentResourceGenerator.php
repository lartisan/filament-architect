<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\Support\FilamentResourceUpdater;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\ColumnDefinition;

readonly class FilamentResourceGenerator extends AbstractGenerator
{
    private const array FOREIGN_COLUMN_SUFFIXES = ['_id', '_uuid', '_ulid'];

    private const array PREFERRED_RELATIONSHIP_TITLE_COLUMNS = [
        'name',
        'title',
        'label',
        'full_name',
        'display_name',
        'email',
        'slug',
        'code',
    ];

    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('filament-resource');
        $modelName = $blueprint->modelName;
        $modelPlural = Str::plural($modelName);

        return $this->replacePlaceholders($stub, [
            '{{ namespace }}' => config('architect.resources_namespace', 'App\\Filament\\Resources'),
            '{{ model_namespace }}' => GenerationPathResolver::modelsNamespace(),
            '{{ model_class }}' => $modelName,
            '{{ model_plural_class }}' => $modelPlural,
            '{{ resource_class }}' => "{$modelName}Resource",
            '{{ form_schema }}' => $this->generateFormSchema($blueprint),
            '{{ table_columns }}' => $this->generateTableColumns($blueprint),
            '{{ infolist_schema }}' => $this->generateInfolistSchema($blueprint),
            '{{ pages_namespace }}' => config('architect.resources_namespace', 'App\\Filament\\Resources')."\\{$modelName}Resource\\Pages",
            // Soft Deletes
            '{{ soft_deletes_import }}' => $blueprint->softDeletes ? "use Illuminate\Database\Eloquent\Builder;\nuse Illuminate\Database\Eloquent\SoftDeletingScope;" : '',
            '{{ soft_deletes_filter }}' => $blueprint->softDeletes ? "Tables\Filters\TrashedFilter::make()," : '//',
            '{{ soft_deletes_bulk_actions }}' => $blueprint->softDeletes ? "\Filament\Actions\ForceDeleteBulkAction::make(),\n                    \Filament\Actions\RestoreBulkAction::make()," : '',
            '{{ eloquent_query }}' => $this->generateEloquentQuery($blueprint),
        ]);
    }

    private function generateEloquentQuery(BlueprintData $blueprint): string
    {
        if (! $blueprint->softDeletes) {
            return '';
        }

        return <<<'PHP'

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
PHP;
    }

    private function isForeignColumn(ColumnDefinition $col): bool
    {
        return in_array($col->type, ['foreignId', 'foreignUuid', 'foreignUlid']) ||
               Str::endsWith($col->name, self::FOREIGN_COLUMN_SUFFIXES);
    }

    private function extractRelationshipName(string $columnName): string
    {
        foreach (self::FOREIGN_COLUMN_SUFFIXES as $suffix) {
            if (Str::endsWith($columnName, $suffix)) {
                return Str::camel(str_replace($suffix, '', $columnName));
            }
        }

        return Str::camel($columnName);
    }

    private function generateFormSchema(BlueprintData $blueprint): string
    {
        return collect($blueprint->columns)
            ->map(function ($col) {
                /** @var ColumnDefinition $col */
                if ($this->isForeignColumn($col)) {
                    $relationshipName = $this->extractRelationshipName($col->name);
                    $titleAttribute = $this->resolveRelationshipDisplayColumn($col, $relationshipName);

                    $component = "Forms\Components\Select::make('{$col->name}')
                    ->relationship('{$relationshipName}', '{$titleAttribute}')";

                    if ($col->index) {
                        $component .= "\n                    ->searchable()";
                    }
                } else {
                    $component = match ($col->type) {
                        'boolean' => "Forms\Components\Toggle::make('{$col->name}')",
                        'date' => "Forms\Components\DatePicker::make('{$col->name}')",
                        'dateTime' => "Forms\Components\DateTimePicker::make('{$col->name}')",
                        'text' => "Forms\Components\Textarea::make('{$col->name}')",
                        'json' => "Forms\Components\KeyValue::make('{$col->name}')",
                        'integer', 'unsignedBigInteger' => "Forms\Components\TextInput::make('{$col->name}')->numeric()",
                        'uuid' => "Forms\Components\TextInput::make('{$col->name}')->uuid()",
                        default => "Forms\Components\TextInput::make('{$col->name}')",
                    };
                }

                if (! $col->nullable) {
                    $component .= "\n                    ->required()";
                }

                if ($col->unique) {
                    $component .= "\n                    ->unique(ignoreRecord: true)";
                }

                return $component.',';
            })
            ->implode("\n                ");
    }

    private function generateTableColumns(BlueprintData $blueprint): string
    {
        return collect($blueprint->columns)
            ->map(function ($col) {
                /** @var ColumnDefinition $col */
                if ($this->isForeignColumn($col)) {
                    $relationshipName = $this->extractRelationshipName($col->name);
                    $titleAttribute = $this->resolveRelationshipDisplayColumn($col, $relationshipName);

                    $columnClass = "Tables\Columns\TextColumn::make('{$relationshipName}.{$titleAttribute}')\n                    ->label('".Str::headline($relationshipName)."')";
                } else {
                    $columnClass = match ($col->type) {
                        'boolean' => "Tables\Columns\IconColumn::make('{$col->name}')->boolean()",
                        'date', 'dateTime' => "Tables\Columns\TextColumn::make('{$col->name}')->dateTime()",
                        default => "Tables\Columns\TextColumn::make('{$col->name}')",
                    };
                }

                if ($col->index || Str::endsWith($col->name, self::FOREIGN_COLUMN_SUFFIXES)) {
                    $columnClass .= "\n                    ->sortable()\n                    ->searchable()";
                }

                return $columnClass.',';
            })
            ->implode("\n                ");
    }

    private function generateInfolistSchema(BlueprintData $blueprint): string
    {
        return collect($blueprint->columns)
            ->map(function ($col) {
                $component = match ($col->type) {
                    'boolean' => "\Filament\Infolists\Components\IconEntry::make('{$col->name}')->boolean()",
                    'date', 'dateTime' => "\Filament\Infolists\Components\TextEntry::make('{$col->name}')->dateTime()",
                    'json' => "\Filament\Infolists\Components\KeyValueEntry::make('{$col->name}')",
                    default => "\Filament\Infolists\Components\TextEntry::make('{$col->name}')",
                };

                return $component.',';
            })
            ->implode("\n                ");
    }

    public function generate(BlueprintData $blueprint): string
    {
        $resourceName = "{$blueprint->modelName}Resource";
        $resourceDir = GenerationPathResolver::resourceDirectory($resourceName);
        $resourcePath = GenerationPathResolver::resource($resourceName);

        $this->ensureDirectoryExists($resourcePath);

        if (! File::isDirectory("{$resourceDir}/Pages")) {
            File::makeDirectory("{$resourceDir}/Pages", 0755, true);
        }

        if (File::exists($resourcePath) && $blueprint->generationMode->shouldMergeExistingArtifacts()) {
            $updatedContent = app(FilamentResourceUpdater::class)->merge(File::get($resourcePath), $this->getContent($blueprint));
            $this->writeFormattedFile($resourcePath, $updatedContent);
        } elseif (! File::exists($resourcePath) || $blueprint->generationMode === GenerationMode::Replace) {
            $this->writeFormattedFile($resourcePath, $this->getContent($blueprint));
        }

        $this->generateResourcePages($blueprint, $resourceDir);

        return $resourcePath;
    }

    protected function generateResourcePages(BlueprintData $blueprint, string $directory): void
    {
        $modelName = $blueprint->modelName;
        $modelPlural = Str::plural($modelName);
        $resourceClass = "{$modelName}Resource";
        $namespace = config('architect.resources_namespace', 'App\\Filament\\Resources');

        $pages = [
            'List' => [
                'stub' => 'filament-resource-list',
                'fileName' => "List{$modelPlural}.php",
            ],
            'Create' => [
                'stub' => 'filament-resource-create',
                'fileName' => "Create{$modelName}.php",
            ],
            'Edit' => [
                'stub' => 'filament-resource-edit',
                'fileName' => "Edit{$modelName}.php",
            ],
            'View' => [
                'stub' => 'filament-resource-view',
                'fileName' => "View{$modelName}.php",
            ],
        ];

        foreach ($pages as $config) {
            $content = $this->getStub($config['stub']);

            $content = $this->replacePlaceholders($content, [
                '{{ namespace }}' => $namespace,
                '{{ resource_class }}' => $resourceClass,
                '{{ model_class }}' => $modelName,
                '{{ model_plural_class }}' => $modelPlural,
            ]);

            $path = "{$directory}/Pages/{$config['fileName']}";

            if (! File::exists($path) || $blueprint->generationMode === GenerationMode::Replace) {
                $this->writeFormattedFile($path, $content);
            }
        }
    }

    private function resolveRelationshipDisplayColumn(ColumnDefinition $column, string $relationshipName): string
    {
        if (filled($column->relationshipTitleColumn)) {
            return (string) $column->relationshipTitleColumn;
        }

        $tableName = $this->resolveRelationshipTableName($column, $relationshipName);
        $fallback = $this->resolveRelationshipKeyName($relationshipName);

        if ($tableName === null || ! Schema::hasTable($tableName)) {
            return $fallback;
        }

        $columnNames = collect(Schema::getColumns($tableName))
            ->pluck('name')
            ->filter(fn ($name) => is_string($name))
            ->map(fn (string $name) => strtolower($name))
            ->values()
            ->all();

        foreach (self::PREFERRED_RELATIONSHIP_TITLE_COLUMNS as $candidate) {
            if (in_array($candidate, $columnNames, true)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private function resolveRelationshipTableName(ColumnDefinition $column, string $relationshipName): ?string
    {
        if (filled($column->relationshipTable)) {
            return (string) $column->relationshipTable;
        }

        $relatedModel = $this->resolveRelatedModel($relationshipName);

        if ($relatedModel instanceof EloquentModel) {
            return $relatedModel->getTable();
        }

        return Str::snake(Str::pluralStudly(Str::studly($relationshipName)));
    }

    private function resolveRelationshipKeyName(string $relationshipName): string
    {
        $relatedModel = $this->resolveRelatedModel($relationshipName);

        return $relatedModel instanceof EloquentModel
            ? $relatedModel->getKeyName()
            : 'id';
    }

    private function resolveRelatedModel(string $relationshipName): ?EloquentModel
    {
        $modelClass = trim(GenerationPathResolver::modelsNamespace(), '\\').'\\'.Str::studly($relationshipName);

        if (! class_exists($modelClass)) {
            return null;
        }

        $model = app($modelClass);

        return $model instanceof EloquentModel ? $model : null;
    }
}
