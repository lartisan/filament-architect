<?php

namespace Lartisan\Architect\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lartisan\Architect\ArchitectPlugin;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\Generators\FactoryGenerator;
use Lartisan\Architect\Generators\FilamentResourceGenerator;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Generators\SeederGenerator;
use Lartisan\Architect\Livewire\BlueprintsTable;
use Lartisan\Architect\Support\ArchitectMigrationStatus;
use Lartisan\Architect\Support\ArchitectUiExtensionRegistry;
use Lartisan\Architect\Support\BlueprintGenerationService;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Support\RegenerationPlanner;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\PlannedSchemaOperation;
use Lartisan\Architect\ValueObjects\RegenerationPlan;
use Lartisan\FilamentArchitectPro\ArchitectProServiceProvider;

class ArchitectAction extends Action
{
    private const CREATE_EDIT_TAB_KEY = 'architect-create-edit-tab';

    private const EXISTING_RESOURCES_TAB_KEY = 'architect-existing-resources-tab';

    public static function getDefaultName(): ?string
    {
        return 'openArchitect';
    }

    protected function setup(): void
    {
        parent::setup();

        $isProInstalled = class_exists(ArchitectProServiceProvider::class);

        $this->label($isProInstalled ? 'ArchitectPRO' : 'Architect')
            ->modalHeading($isProInstalled ? 'ArchitectPRO' : 'Architect')
            ->modalDescription(__('Generate Eloquent model, migration, factory and seeder along with the associated Filament resource.'))
            ->modalIcon(Heroicon::Square3Stack3d)
            ->icon(Heroicon::Square3Stack3d)
            ->modalWidth(Width::FiveExtraLarge)
            ->slideOver()
            ->schema([
                Callout::make()
                    ->view('architect::components.pro-cta')
                    ->hidden($isProInstalled),

                Tabs::make('Tabs')
                    ->tabs(self::tabs())
                    ->extraAttributes([
                        'x-on:activate-first-tab.window' => "\$data.tab = '".self::CREATE_EDIT_TAB_KEY."';",
                    ]),
            ])
            ->action(function (array $data) {
                try {
                    $blueprintData = BlueprintData::fromArray($data, shouldValidate: true);
                    [
                        'plan' => $plan,
                        'shouldRunMigration' => $shouldRunMigration,
                        'deletedLegacyFiles' => $deletedLegacyFiles,
                        'modifiedLegacyFiles' => $modifiedLegacyFiles,
                    ] = app(BlueprintGenerationService::class)->generate($blueprintData);

                    Notification::make()->title('Succes!')->success()->send();

                    if ($blueprintData->runMigration && ! $shouldRunMigration) {
                        $warningBody = collect($plan->getDeferredRiskySchemaChanges())
                            ->map(fn (PlannedSchemaOperation $operation) => '• '.$operation->description)
                            ->implode("\n");

                        Notification::make()
                            ->title(__('Migration deferred for safety'))
                            ->body($warningBody)
                            ->warning()
                            ->send();
                    }

                    if ($deletedLegacyFiles !== []) {
                        $fileList = collect($deletedLegacyFiles)
                            ->map(fn (string $path) => '• '.Str::after($path, base_path().DIRECTORY_SEPARATOR))
                            ->implode("\n");

                        Notification::make()
                            ->title(__('Legacy Filament v3 files removed'))
                            ->body(__(
                                "The following unmodified v3 resource files were automatically deleted:\n\n:files",
                                ['files' => $fileList]
                            ))
                            ->info()
                            ->send();
                    }

                    if ($modifiedLegacyFiles !== []) {
                        $fileList = collect($modifiedLegacyFiles)
                            ->map(fn (string $path) => '• '.Str::after($path, base_path().DIRECTORY_SEPARATOR))
                            ->implode("\n");

                        Notification::make()
                            ->title(__('Legacy Filament v3 files detected'))
                            ->body(__(
                                "The following v3 resource files appear to have been customised and were not removed automatically. Please review and delete them once you have confirmed the new v4 structure is working correctly:\n\n:files",
                                ['files' => $fileList]
                            ))
                            ->warning()
                            ->persistent()
                            ->send();
                    }

                    if ($blueprintData->generateResource) {
                        return redirect()->to('/'.Filament::getCurrentPanel()->getId().'/'.Str::kebab(Str::plural($blueprintData->modelName)));
                    }

                    return null;
                } catch (\Exception $e) {
                    Notification::make()->title('Error')->body($e->getMessage())->danger()->send();

                    return null;
                }
            })
            ->closeModalByClickingAway(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalSubmitAction(false)
            ->extraModalWindowAttributes(['class' => 'architect-modal'])
            ->modalFooterActionsAlignment(Alignment::Start)
            ->modalFooterActions(function (): array {
                return [
                    $this->getModalCancelAction(),
                    Action::make('architect_version_badge')
                        ->badge()
                        ->label(__('Plugin version').' '.ArchitectPlugin::version())
                        ->color('gray')
                        ->disabled()
                        ->extraAttributes(['class' => 'ms-auto']),
                ];
            });
    }

    /**
     * @return array<int, Tabs\Tab>
     */
    protected static function tabs(): array
    {
        return [
            self::existingResourcesTab(),
            self::createEditTab(),
            ...app(ArchitectUiExtensionRegistry::class)->tabs(),
        ];
    }

    protected static function createEditTab(): Tabs\Tab
    {
        return Tabs\Tab::make(__('Create / Edit'))
            ->key(self::CREATE_EDIT_TAB_KEY)
            ->icon(Heroicon::PencilSquare)
            ->id('tab-create')
            ->schema([
                Wizard::make([
                    ...self::databaseStep(),
                    ...self::eloquentStep(),
                    ...self::reviewStep(),
                ])
                    ->extraAlpineAttributes([
                        // Prevent Livewire morphdom from overwriting Alpine.js-managed
                        // visibility state (x-cloak / fi-hidden) on the wizard footer
                        // when any live() field triggers a component re-render.
                        'x-init' => '$nextTick(() => { const f = $el.querySelector(\'.fi-sc-wizard-footer\'); if (f) f.__livewire_ignore = true; })',
                    ])
                    ->submitAction(
                        Action::make('submit')
                            ->label('Save & Generate')
                            ->submit('save'),
                    ),
                ...app(ArchitectUiExtensionRegistry::class)->createEditExtensions(),
            ]);
    }

    protected static function existingResourcesTab(): Tabs\Tab
    {
        return Tabs\Tab::make(__('Blueprints'))
            ->key(self::EXISTING_RESOURCES_TAB_KEY)
            ->visible(fn () => app(ArchitectMigrationStatus::class)->hasStoredRevisions())
            ->icon(Heroicon::ListBullet)
            ->id('tab-list')
            ->schema([
                Livewire::make(BlueprintsTable::class)
                    ->key('blueprints-table-view'),
                ...app(ArchitectUiExtensionRegistry::class)->existingResourcesExtensions(),
            ]);
    }

    protected static function databaseStep(): array
    {
        return [
            Wizard\Step::make('Database')
                ->description(__('Table Configuration'))
                ->icon('heroicon-o-table-cells')
                ->key('architect-database-step')
                ->schema([
                    Group::make()
                        ->columns(3)
                        ->schema([
                            TextInput::make('table_name')
                                ->label(__('Table Name (plural)'))
                                ->placeholder('ex: projects, task_items')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, ?string $state) {
                                    $exists = Schema::hasTable($state);
                                    $set('table_exists', $exists);

                                    if (! $exists) {
                                        $set('overwrite_table', false);
                                    }

                                    $set('model_name', Str::studly(Str::singular($state)));
                                }),

                            Select::make('primary_key_type')
                                ->label(__('Primary Key Type'))
                                ->options([
                                    'id' => 'Auto-increment (BigInt)',
                                    'uuid' => 'UUID (String)',
                                    'ulid' => 'ULID (String)',
                                ])
                                ->default('id')
                                ->required()
                                ->live(),

                            Toggle::make('soft_deletes')
                                ->label(__('Soft Deletes'))
                                ->live()
                                ->inline(false)
                                ->default(false),
                        ]),

                    Toggle::make('overwrite_table')
                        ->label(__('Overwrite existing table'))
                        ->helperText(__('Warning: The current table and all included data will be deleted (DROP TABLE)!'))
                        ->visible(fn ($get) => $get('table_exists') && $get('generation_mode') === GenerationMode::Replace->value)
                        ->onColor('danger')
                        ->default(false)
                        ->live(),

                    Hidden::make('table_exists'),
                    Hidden::make('meta')
                        ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state) : $state)
                        ->dehydrateStateUsing(fn ($state) => is_string($state) ? json_decode($state, true) : $state),

                    Repeater::make('columns')
                        ->label(__('Columns'))
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    TextInput::make('name')
                                        ->label(__('Column Name'))
                                        ->placeholder('ex: title, price_ct')
                                        ->live(onBlur: true)
                                        ->required(),

                                    Select::make('type')
                                        ->label(__('Data Type'))
                                        ->options([
                                            'string' => 'String (VARCHAR)',
                                            'text' => 'Text (LONGTEXT)',
                                            'integer' => 'Integer',
                                            'unsignedBigInteger' => 'Unsigned BigInt',
                                            'boolean' => 'Boolean',
                                            'json' => 'JSON',
                                            'date' => 'Date',
                                            'dateTime' => 'DateTime',
                                            'foreignId' => 'Foreign ID (Relation)',
                                            'foreignUuid' => 'Foreign UUID (Relation)',
                                            'foreignUlid' => 'Foreign ULID (Relation)',
                                        ])
                                        ->required()
                                        ->live(),

                                    TextInput::make('default')
                                        ->label(__('Default Value'))
                                        ->live(onBlur: true)
                                        ->placeholder('NULL'),
                                ]),

                            Grid::make(3)
                                ->schema([
                                    Toggle::make('is_nullable')
                                        ->live()
                                        ->label('Nullable'),
                                    Toggle::make('is_unique')
                                        ->live()
                                        ->label('Unique'),
                                    Toggle::make('is_index')
                                        ->live()
                                        ->label('Index'),
                                ]),

                            Grid::make(2)
                                ->schema([
                                    Select::make('relationship_table')
                                        ->label(__('Related Table'))
                                        ->helperText(__('Optional. Use an existing database table to drive relationship field generation.'))
                                        ->options(fn () => self::relationshipTableOptions())
                                        ->placeholder(__('Choose a related table'))
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->visible(fn ($get) => self::isForeignRelationshipType($get('type')))
                                        ->afterStateUpdated(fn (Set $set) => $set('relationship_title_column', null)),

                                    Select::make('relationship_title_column')
                                        ->label(__('Relationship Title Column'))
                                        ->helperText(__('Optional. This column will be used as the Filament relationship title attribute.'))
                                        ->options(fn ($get) => self::relationshipTitleColumnOptions($get('relationship_table')))
                                        ->placeholder(fn ($get) => filled($get('relationship_table')) ? __('Choose a title column') : __('Select a related table first'))
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->visible(fn ($get) => self::isForeignRelationshipType($get('type'))),
                                ]),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->collapsible()
                        ->addAction(fn (Action $action, Repeater $component) => $action->extraAttributes([
                            'x-on:click' => sprintf("\$dispatch('repeater-collapse', '%s')", $component->getStatePath()),
                        ], merge: true))
                        ->defaultItems(1)
                        ->reorderable(),
                ]),
        ];
    }

    protected static function eloquentStep(): array
    {
        return [
            Wizard\Step::make('Eloquent')
                ->description(__('Model and associated classes'))
                ->icon('heroicon-o-cube')
                ->key('architect-eloquent-step')
                ->beforeValidation(fn (Get $get) => self::validateReviewRequirements($get))
                ->schema([
                    TextInput::make('model_name')
                        ->label(__('Model Name'))
                        ->helperText(__('Automatically generated from the table name, but can be modified.'))
                        ->live(onBlur: true)
                        ->required(),

                    Select::make('generation_mode')
                        ->label(__('Generation Mode'))
                        ->options(GenerationMode::options())
                        ->helperText(__('Choose whether Architect should only create missing files, merge managed parts, or replace generated artifacts.'))
                        ->default(GenerationMode::default()->value)
                        ->native(false)
                        ->live(),

                    Grid::make(2)
                        ->schema([
                            Toggle::make('gen_factory')
                                ->label(__('Generate Factory'))
                                ->live()
                                ->default(config('architect.generate_factory', true)),

                            Toggle::make('gen_seeder')
                                ->label(__('Generate Seeder'))
                                ->live()
                                ->default(config('architect.generate_seeder', true)),

                            Toggle::make('gen_resource')
                                ->label(__('Generate Filament Resource'))
                                ->helperText(__('Automatically creates Resource, List, Create, Edit and View Pages.'))
                                ->live()
                                ->default(config('architect.generate_resource', true)),
                        ]),

                    Grid::make(2)
                        ->schema([
                            Toggle::make('run_migration')
                                ->label(__('Run migration immediately'))
                                ->helperText(__('If enabled, the table will be created in the database immediately after generating the files.'))
                                ->default(true)
                                ->live(),

                            Toggle::make('allow_likely_renames')
                                ->label(__('Allow likely column renames'))
                                ->helperText(__('Enable this only if the suggested rename is correct.'))
                                ->visible(fn ($get) => self::planHasSchemaAction($get, 'rename'))
                                ->default(false)
                                ->live(),

                            Toggle::make('allow_destructive_changes')
                                ->label(__('Allow destructive schema changes'))
                                ->helperText(__('Enable this to allow dropping columns or removing soft deletes in the generated sync migration.'))
                                ->visible(fn ($get) => self::planHasSchemaAction($get, 'remove'))
                                ->default(false)
                                ->live(),
                        ]),
                ]),
        ];
    }

    protected static function reviewStep(): array
    {
        return [
            Wizard\Step::make('Review')
                ->description(__('Preview the generated files'))
                ->schema([
                    Tabs::make('Code Preview')
                        ->tabs([
                            Tabs\Tab::make('Migration')
                                ->icon(Heroicon::CircleStack)
                                ->schema([
                                    TextEntry::make('migration_preview')
                                        ->live()
                                        ->state(function ($get) {
                                            try {
                                                $data = $get('');
                                                if (empty($data['table_name'])) {
                                                    return '...';
                                                }

                                                $blueprint = BlueprintData::fromArray($data, shouldValidate: false);

                                                return MigrationGenerator::make()->preview($blueprint);
                                            } catch (\Throwable $e) {
                                                return '// '.__('Configuration Error:').' '.$e->getMessage();
                                            }
                                        })
                                        ->formatStateUsing(fn ($state) => view('architect::components.code-preview', [
                                            'code' => $state,
                                            'lang' => 'php',
                                        ]))
                                        ->html(),
                                ]),

                            Tabs\Tab::make('Model')
                                ->icon(Heroicon::Cube)
                                ->schema([
                                    TextEntry::make('model_code')
                                        ->live()
                                        ->state(function ($get) {
                                            $data = $get('');

                                            if (empty($data['table_name'])) {
                                                return __('Enter table name for preview...');
                                            }

                                            try {
                                                $blueprint = BlueprintData::fromArray($data);

                                                return ModelGenerator::make()->preview($blueprint);
                                            } catch (\Throwable $e) {
                                                return '// '.__('Waiting for valid data to generate...');
                                            }
                                        })
                                        ->formatStateUsing(fn ($state) => view('architect::components.code-preview', [
                                            'code' => $state,
                                            'lang' => 'php',
                                        ]))
                                        ->html(),
                                ]),

                            Tabs\Tab::make('Factory')
                                ->icon(Heroicon::Wrench)
                                ->schema([
                                    TextEntry::make('factory_code')
                                        ->live()
                                        ->state(function ($get) {
                                            try {
                                                $data = $get('');
                                                if (empty($data['table_name'])) {
                                                    return null;
                                                }

                                                $blueprint = BlueprintData::fromArray($data, shouldValidate: false);

                                                return FactoryGenerator::make()->preview($blueprint);
                                            } catch (\Throwable $e) {
                                                return '// '.__('Factory preview will appear after defining columns... ');
                                            }
                                        })
                                        ->formatStateUsing(fn ($state) => view('architect::components.code-preview', [
                                            'code' => $state,
                                            'lang' => 'php',
                                        ]))
                                        ->html(),
                                ]),

                            Tabs\Tab::make('Seeder')
                                ->icon(Heroicon::Variable)
                                ->visible(fn ($get) => $get('gen_seeder'))
                                ->schema([
                                    TextEntry::make('seeder_preview')
                                        ->state(fn ($get) => SeederGenerator::make()->preview(BlueprintData::fromArray($get(''))))
                                        ->formatStateUsing(fn ($state) => view('architect::components.code-preview', ['code' => $state]))
                                        ->html(),
                                ]),

                            Tabs\Tab::make('Resource')
                                ->icon('heroicon-o-rectangle-group')
                                ->visible(fn ($get) => $get('gen_resource'))
                                ->schema(GenerationPathResolver::isFilamentV4()
                                    ? self::resourcePreviewSections()
                                    : self::resourcePreviewSingle()
                                ),
                        ]),
                ]),
        ];
    }

    /**
     * v3: single code block — the full monolithic resource.
     *
     * @return array<int, TextEntry>
     */
    protected static function resourcePreviewSingle(): array
    {
        return [
            TextEntry::make('resource_preview')
                ->live()
                ->state(function ($get) {
                    try {
                        $data = $get('');

                        if (empty($data['table_name'])) {
                            return '...';
                        }

                        return FilamentResourceGenerator::make()->preview(
                            BlueprintData::fromArray($data, shouldValidate: false)
                        );
                    } catch (\Throwable $e) {
                        return '// '.__('Configuration Error:').' '.$e->getMessage();
                    }
                })
                ->formatStateUsing(fn ($state) => view('architect::components.code-preview', ['code' => $state]))
                ->html(),
        ];
    }

    /**
     * v4: one collapsible Section per generated file (Resource, Form, Infolist, Table).
     *
     * @return array<int, Section>
     */
    protected static function resourcePreviewSections(): array
    {
        $generator = FilamentResourceGenerator::make();

        /**
         * Factory: produces a live TextEntry with a guarded state closure.
         *
         * @param  callable(BlueprintData): string  $contentResolver
         */
        $entry = fn (string $key, callable $contentResolver): TextEntry => TextEntry::make($key)
            ->live()
            ->state(function ($get) use ($contentResolver) {
                try {
                    $data = $get('');

                    if (empty($data['table_name'])) {
                        return '...';
                    }

                    return $contentResolver(BlueprintData::fromArray($data, shouldValidate: false));
                } catch (\Throwable $e) {
                    return '// '.__('Configuration Error:').' '.$e->getMessage();
                }
            })
            ->formatStateUsing(fn ($state) => view('architect::components.code-preview', ['code' => $state]))
            ->html();

        return [
            // Thin resource — less relevant for review, starts collapsed
            Section::make(fn ($get) => ($get('model_name') ?: 'Model').'Resource.php')
                ->icon('heroicon-o-rectangle-group')
                ->collapsed()
                ->schema([$entry('resource_preview', fn (BlueprintData $bp) => $generator->previewResource($bp))]),

            // Form — most important for review, starts expanded
            Section::make(fn ($get) => 'Schemas/'.($get('model_name') ?: 'Model').'Form.php')
                ->icon('heroicon-o-pencil-square')
                ->collapsible()
                ->schema([$entry('resource_form_preview', fn (BlueprintData $bp) => $generator->previewForm($bp))]),

            // Infolist — starts collapsed
            Section::make(fn ($get) => 'Schemas/'.($get('model_name') ?: 'Model').'Infolist.php')
                ->icon('heroicon-o-eye')
                ->collapsed()
                ->schema([$entry('resource_infolist_preview', fn (BlueprintData $bp) => $generator->previewInfolist($bp))]),

            // Table — important for review, starts expanded
            Section::make(fn ($get) => 'Tables/'.Str::pluralStudly($get('model_name') ?: 'Model').'Table.php')
                ->icon('heroicon-o-table-cells')
                ->collapsible()
                ->schema([$entry('resource_table_preview', fn (BlueprintData $bp) => $generator->previewTable($bp))]),
        ];
    }

    private static function resolvePlanFromState($get): ?RegenerationPlan
    {
        try {
            $data = $get('');
            if (empty($data['table_name'])) {
                return null;
            }

            return app(RegenerationPlanner::class)->plan(BlueprintData::fromArray($data, shouldValidate: false));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function planHasSchemaAction($get, string $action): bool
    {
        $plan = self::resolvePlanFromState($get);

        if (! $plan instanceof RegenerationPlan) {
            return false;
        }

        return collect($plan->schemaOperations)
            ->contains(fn (PlannedSchemaOperation $operation) => $operation->action === $action);
    }

    private static function validateReviewRequirements(Get $get): void
    {
        $plan = self::resolvePlanFromState($get);

        if (! $plan instanceof RegenerationPlan) {
            return;
        }

        $message = self::reviewValidationMessage($plan);

        if ($message === null) {
            return;
        }

        Notification::make()
            ->title($message)
            ->danger()
            ->send();

        throw new Halt;
    }

    private static function reviewValidationMessage(RegenerationPlan $plan): ?string
    {
        if ($plan->hasBlockingSchemaChanges()) {
            return self::blockingSchemaChangesValidationMessage($plan);
        }

        if (! $plan->hasSchemaChanges()) {
            return __('Architect did not detect any schema changes for this table. Update the schema before continuing.');
        }

        return null;
    }

    private static function blockingSchemaChangesValidationMessage(RegenerationPlan $plan): string
    {
        $columns = collect($plan->getBlockingSchemaChanges())
            ->map(fn (PlannedSchemaOperation $operation) => Str::after($operation->description, 'Add column '))
            ->implode(', ');

        return __('This table already contains data. Make these new columns nullable, provide a default value, or backfill existing rows before continuing: :columns.', [
            'columns' => $columns,
        ]);
    }

    private static function isForeignRelationshipType(mixed $type): bool
    {
        return in_array((string) $type, ['foreignId', 'foreignUuid', 'foreignUlid'], true);
    }

    private static function relationshipTableOptions(): array
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->mapWithKeys(fn (string $table) => [$table => Str::headline(Str::replace('_', ' ', $table))])
            ->all();
    }

    private static function relationshipTitleColumnOptions(mixed $table): array
    {
        $table = filled($table) ? (string) $table : null;

        if ($table === null || ! Schema::hasTable($table)) {
            return [];
        }

        return collect(Schema::getColumns($table))
            ->pluck('name')
            ->filter(fn ($name) => is_string($name))
            ->mapWithKeys(fn (string $column) => [$column => Str::headline(Str::replace('_', ' ', $column))])
            ->all();
    }
}
