<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\ColumnDefinition;

readonly class FilamentResourceGenerator extends AbstractGenerator
{
    private const array FOREIGN_COLUMN_SUFFIXES = ['_id', '_uuid', '_ulid'];

    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('filament-resource');
        $modelName = $blueprint->modelName;
        $modelPlural = Str::plural($modelName);

        return $this->replacePlaceholders($stub, [
            '{{ namespace }}' => config('architect.resources_namespace', 'App\\Filament\\Resources'),
            '{{ model_namespace }}' => config('architect.models_namespace', 'App\\Models'),
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

                    $component = "Forms\Components\Select::make('{$col->name}')
                    ->relationship('{$relationshipName}', 'name')";

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

                    $columnClass = "Tables\Columns\TextColumn::make('{$relationshipName}.name')\n                    ->label('".Str::headline($relationshipName)."')";
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
        $basePath = app_path('Filament/Resources');
        $resourceDir = "{$basePath}/{$resourceName}";
        $resourcePath = "{$basePath}/{$resourceName}.php";

        $this->ensureDirectoryExists($resourcePath);

        if (! File::isDirectory("{$resourceDir}/Pages")) {
            File::makeDirectory("{$resourceDir}/Pages", 0755, true);
        }

        File::put($resourcePath, $this->getContent($blueprint));

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

            File::put($path, $content);
        }
    }
}
