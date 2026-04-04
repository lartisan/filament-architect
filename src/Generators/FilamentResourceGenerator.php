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

        $baseNamespace = (string) config('architect.resources_namespace', 'App\\Filament\\Resources');
        $resourceNamespace = GenerationPathResolver::isFilamentV4()
            ? GenerationPathResolver::resourceNamespace($modelName)
            : $baseNamespace;

        return $this->replacePlaceholders($stub, [
            // Namespace placeholders — v3 uses {{ namespace }}, v4 uses {{ resource_namespace }}
            '{{ namespace }}' => $baseNamespace,
            '{{ resource_namespace }}' => $resourceNamespace,
            '{{ schemas_namespace }}' => GenerationPathResolver::isFilamentV4()
                ? GenerationPathResolver::resourceSchemasNamespace($modelName)
                : $baseNamespace,
            '{{ tables_namespace }}' => GenerationPathResolver::isFilamentV4()
                ? GenerationPathResolver::resourceTablesNamespace($modelName)
                : $baseNamespace,
            // Model / resource identifiers
            '{{ model_namespace }}' => GenerationPathResolver::modelsNamespace(),
            '{{ model_class }}' => $modelName,
            '{{ model_plural_class }}' => $modelPlural,
            '{{ resource_class }}' => "{$modelName}Resource",
            // Inline schema content — used by v3 resource stub; harmlessly ignored by v4 thin resource stub
            '{{ form_schema }}' => $this->generateFormSchema($blueprint),
            '{{ table_columns }}' => $this->generateTableColumns($blueprint),
            '{{ infolist_schema }}' => $this->generateInfolistSchema($blueprint),
            // Soft deletes
            '{{ soft_deletes_import }}' => $blueprint->softDeletes
                ? "use Illuminate\Database\Eloquent\Builder;\nuse Illuminate\Database\Eloquent\SoftDeletingScope;"
                : '',
            '{{ soft_deletes_filter }}' => $blueprint->softDeletes ? "Tables\Filters\TrashedFilter::make()," : '//',
            '{{ soft_deletes_bulk_actions }}' => $blueprint->softDeletes
                ? "\Filament\Actions\ForceDeleteBulkAction::make(),\n                    \Filament\Actions\RestoreBulkAction::make(),"
                : '',
            '{{ eloquent_query }}' => $this->generateEloquentQuery($blueprint),
        ]);
    }

    // -------------------------------------------------------------------------
    // Public preview API (used by ArchitectAction::reviewStep sections)
    // -------------------------------------------------------------------------

    /**
     * For v3: returns the single monolithic resource file content.
     * For v4: returns all four generated files concatenated with file-path headers.
     */
    public function preview(BlueprintData $blueprint): string
    {
        if (! GenerationPathResolver::isFilamentV4()) {
            return $this->getContent($blueprint);
        }

        $modelName = $blueprint->modelName;
        $modelPlural = Str::pluralStudly($modelName);

        $files = [
            "{$modelName}Resource.php" => $this->getContent($blueprint),
            "Schemas/{$modelName}Form.php" => $this->getFormContent($blueprint),
            "Schemas/{$modelName}Infolist.php" => $this->getInfolistContent($blueprint),
            "Tables/{$modelPlural}Table.php" => $this->getTableContent($blueprint),
        ];

        return collect($files)
            ->map(fn (string $content, string $path) => "// ── {$path} ──\n\n{$content}")
            ->implode("\n\n\n");
    }

    public function previewResource(BlueprintData $blueprint): string
    {
        return $this->getContent($blueprint);
    }

    public function previewForm(BlueprintData $blueprint): string
    {
        return $this->getFormContent($blueprint);
    }

    public function previewInfolist(BlueprintData $blueprint): string
    {
        return $this->getInfolistContent($blueprint);
    }

    public function previewTable(BlueprintData $blueprint): string
    {
        return $this->getTableContent($blueprint);
    }

    // -------------------------------------------------------------------------
    // Protected content builders (protected for testability & extensibility)
    // -------------------------------------------------------------------------

    protected function getFormContent(BlueprintData $blueprint): string
    {
        $modelName = $blueprint->modelName;
        $baseNamespace = (string) config('architect.resources_namespace', 'App\\Filament\\Resources');

        return $this->replacePlaceholders($this->getStub('filament-schemas-form'), [
            '{{ schemas_namespace }}' => GenerationPathResolver::isFilamentV4()
                ? GenerationPathResolver::resourceSchemasNamespace($modelName)
                : $baseNamespace,
            '{{ model_class }}' => $modelName,
            '{{ form_schema }}' => $this->generateFormSchema($blueprint),
        ]);
    }

    protected function getInfolistContent(BlueprintData $blueprint): string
    {
        $modelName = $blueprint->modelName;
        $baseNamespace = (string) config('architect.resources_namespace', 'App\\Filament\\Resources');

        return $this->replacePlaceholders($this->getStub('filament-schemas-infolist'), [
            '{{ schemas_namespace }}' => GenerationPathResolver::isFilamentV4()
                ? GenerationPathResolver::resourceSchemasNamespace($modelName)
                : $baseNamespace,
            '{{ model_class }}' => $modelName,
            '{{ infolist_schema }}' => $this->generateInfolistSchema($blueprint),
        ]);
    }

    protected function getTableContent(BlueprintData $blueprint): string
    {
        $modelName = $blueprint->modelName;
        $modelPlural = Str::plural($modelName);
        $baseNamespace = (string) config('architect.resources_namespace', 'App\\Filament\\Resources');

        return $this->replacePlaceholders($this->getStub('filament-tables-table'), [
            '{{ tables_namespace }}' => GenerationPathResolver::isFilamentV4()
                ? GenerationPathResolver::resourceTablesNamespace($modelName)
                : $baseNamespace,
            '{{ model_class }}' => $modelName,
            '{{ model_plural_class }}' => $modelPlural,
            '{{ table_columns }}' => $this->generateTableColumns($blueprint),
            // Bulk actions use FQCN (\Filament\Actions\...) so no extra imports needed
            '{{ soft_deletes_import }}' => '',
            '{{ soft_deletes_filter }}' => $blueprint->softDeletes ? "Tables\Filters\TrashedFilter::make()," : '//',
            '{{ soft_deletes_bulk_actions }}' => $blueprint->softDeletes
                ? "\Filament\Actions\ForceDeleteBulkAction::make(),\n                    \Filament\Actions\RestoreBulkAction::make(),"
                : '',
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
        $modelName = $blueprint->modelName;
        $resourceName = "{$modelName}Resource";
        $resourceDir = GenerationPathResolver::resourceDirectory($resourceName);
        $resourcePath = GenerationPathResolver::resource($resourceName);

        $this->ensureDirectoryExists($resourcePath);

        if (! File::isDirectory("{$resourceDir}/Pages")) {
            File::makeDirectory("{$resourceDir}/Pages", 0755, true);
        }

        if (GenerationPathResolver::isFilamentV4()) {
            if (! File::isDirectory("{$resourceDir}/Schemas")) {
                File::makeDirectory("{$resourceDir}/Schemas", 0755, true);
            }

            if (! File::isDirectory("{$resourceDir}/Tables")) {
                File::makeDirectory("{$resourceDir}/Tables", 0755, true);
            }
        }

        if (File::exists($resourcePath) && $blueprint->generationMode->shouldMergeExistingArtifacts()) {
            $updater = app(FilamentResourceUpdater::class);
            $updatedContent = GenerationPathResolver::isFilamentV4()
                ? $updater->mergeThinResource(File::get($resourcePath), $this->getContent($blueprint))
                : $updater->merge(File::get($resourcePath), $this->getContent($blueprint));
            $this->writeFormattedFile($resourcePath, $updatedContent);
        } elseif (! File::exists($resourcePath) || $blueprint->generationMode === GenerationMode::Replace) {
            $this->writeFormattedFile($resourcePath, $this->getContent($blueprint));
        }

        if (GenerationPathResolver::isFilamentV4()) {
            $this->generateSeparateSchemaFiles($blueprint);
        }

        $this->generateResourcePages($blueprint, $resourceDir);

        return $resourcePath;
    }

    /**
     * Detect existing Filament v3 (flat) artifacts for the given model name.
     *
     * Returns an array of absolute paths that exist on disk but belong to the
     * old flat structure — the monolithic resource file and every file inside
     * the companion `{ModelName}Resource/` directory. Returns an empty array
     * when not running in v4 mode or when no legacy files are found.
     *
     * Callers should present these paths to the user as files to review and
     * delete manually; Architect does NOT auto-delete them.
     *
     * @return array<int, string>
     */
    public static function detectLegacyV3Artifacts(string $modelName): array
    {
        if (! GenerationPathResolver::isFilamentV4()) {
            return [];
        }

        $resourceName = "{$modelName}Resource";
        $legacyFile = GenerationPathResolver::legacyV3Resource($resourceName);
        $legacyDir = GenerationPathResolver::legacyV3ResourceDirectory($resourceName);

        // The v4 domain directory is the parent of the generated resource file.
        // If the legacy flat file resolves to the same path as the v4 resource
        // (e.g. custom namespace collapses the distinction), skip detection.
        $v4ResourceFile = GenerationPathResolver::resource($resourceName);

        if ($legacyFile === $v4ResourceFile) {
            return [];
        }

        $detected = [];

        if (File::exists($legacyFile)) {
            $detected[] = $legacyFile;
        }

        if (File::isDirectory($legacyDir)) {
            foreach (File::allFiles($legacyDir) as $file) {
                $detected[] = $file->getPathname();
            }
        }

        return $detected;
    }

    /**
     * Write (or merge) the Form, Infolist and Table files for the v4 domain structure.
     */
    protected function generateSeparateSchemaFiles(BlueprintData $blueprint): void
    {
        $modelName = $blueprint->modelName;
        $updater = app(FilamentResourceUpdater::class);

        $artifacts = [
            [
                'path' => GenerationPathResolver::resourceSchemaFile($modelName, 'Form'),
                'content' => fn () => $this->getFormContent($blueprint),
                'merge' => fn (string $existing, string $generated) => $updater->mergeSchemaFile($existing, $generated, 'form'),
            ],
            [
                'path' => GenerationPathResolver::resourceSchemaFile($modelName, 'Infolist'),
                'content' => fn () => $this->getInfolistContent($blueprint),
                'merge' => fn (string $existing, string $generated) => $updater->mergeSchemaFile($existing, $generated, 'infolist'),
            ],
            [
                'path' => GenerationPathResolver::resourceTableFile($modelName),
                'content' => fn () => $this->getTableContent($blueprint),
                'merge' => fn (string $existing, string $generated) => $updater->mergeTableFile($existing, $generated),
            ],
        ];

        foreach ($artifacts as $artifact) {
            $path = $artifact['path'];

            if (File::exists($path) && $blueprint->generationMode->shouldMergeExistingArtifacts()) {
                $updatedContent = ($artifact['merge'])(File::get($path), ($artifact['content'])());
                $this->writeFormattedFile($path, $updatedContent);
            } elseif (! File::exists($path) || $blueprint->generationMode === GenerationMode::Replace) {
                $this->writeFormattedFile($path, ($artifact['content'])());
            }
        }
    }

    protected function generateResourcePages(BlueprintData $blueprint, string $directory): void
    {
        $modelName = $blueprint->modelName;
        $modelPlural = Str::plural($modelName);
        $resourceClass = "{$modelName}Resource";
        $baseNamespace = (string) config('architect.resources_namespace', 'App\\Filament\\Resources');
        $resourceNamespace = GenerationPathResolver::isFilamentV4()
            ? GenerationPathResolver::resourceNamespace($modelName)
            : $baseNamespace;

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
                '{{ namespace }}' => $baseNamespace,
                '{{ resource_namespace }}' => $resourceNamespace,
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
