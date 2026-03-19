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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lartisan\Architect\Generators\FactoryGenerator;
use Lartisan\Architect\Generators\FilamentResourceGenerator;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Generators\SeederGenerator;
use Lartisan\Architect\Livewire\BlueprintsTable;
use Lartisan\Architect\Models\Blueprint as ArchitectBlueprint;
use Lartisan\Architect\ValueObjects\BlueprintData;

class ArchitectAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'openArchitect';
    }

    protected function setup(): void
    {
        parent::setup();

        $this->label('Architect')
            ->modalDescription(__('Generate Eloquent model, migration, factory and seeder along with the associated Filament resource.'))
            ->icon(Heroicon::Square3Stack3d)
            ->modalWidth(Width::FiveExtraLarge)
            ->slideOver()
            ->schema([
                Tabs::make('Tabs')
                    ->tabs([
                        Tabs\Tab::make(__('Create')) // 'Create / Edit'
                            ->icon(Heroicon::PencilSquare)
                            ->schema([
                                Wizard::make([
                                    ...self::databaseStep(),
                                    ...self::eloquentStep(),
                                    ...self::reviewStep(),
                                ])
                                    ->submitAction(
                                        Action::make('submit')
                                            ->label('Save & Generate')
                                            ->submit('save'),
                                    ),
                            ]),

                        Tabs\Tab::make(__('Existing Resources'))
                            ->icon(Heroicon::ListBullet)
                            ->schema([
                                Livewire::make(BlueprintsTable::class)
                                    ->key('blueprints-table-view'),
                            ]),
                    ]),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $blueprintData = BlueprintData::fromArray($data, shouldValidate: true);

                    // Persist to DB
                    ArchitectBlueprint::updateOrCreate(
                        ['table_name' => $blueprintData->tableName],
                        $blueprintData->toFormData()
                    );

                    // Update the form with saved ID if needed, but we regenerate anyway.

                    if ($blueprintData->overwriteTable) {
                        Schema::dropIfExists($blueprintData->tableName);

                        $migrationFiles = glob(database_path('migrations/*_create_'.$blueprintData->tableName.'_table.php'));
                        foreach ($migrationFiles as $file) {
                            if (File::exists($file)) {
                                File::delete($file);
                            }
                        }

                        DB::table('migrations')
                            ->where('migration', 'like', '%_create_'.$blueprintData->tableName.'_table')
                            ->delete();
                    }

                    MigrationGenerator::make()->generate($blueprintData);
                    ModelGenerator::make()->generate($blueprintData);

                    if ($blueprintData->generateFactory) {
                        FactoryGenerator::make()->generate($blueprintData);
                    }

                    if ($blueprintData->generateSeeder) {
                        SeederGenerator::make()->generate($blueprintData);
                    }

                    if ($blueprintData->generateResource) {
                        FilamentResourceGenerator::make()->generate($blueprintData);
                    }

                    if ($blueprintData->runMigration) {
                        Artisan::call('migrate', ['--force' => true]);
                    }

                    Notification::make()->title('Succes!')->success()->send();

                    if ($blueprintData->generateResource) {
                        return redirect()->to('/'.Filament::getCurrentPanel()->getId().'/'.Str::kebab(Str::plural($blueprintData->modelName)));
                    }

                } catch (\Exception $e) {
                    Notification::make()->title('Eroare')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }
            })
            ->closeModalByClickingAway(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalSubmitAction(false);
    }

    protected static function databaseStep(): array
    {
        return [
            Wizard\Step::make('Database')
                ->description(__('Table Configuration'))
                ->icon('heroicon-o-table-cells')
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

                    Toggle::make('overwrite_table')
                        ->label(__('Overwrite existing table'))
                        ->helperText(__('Warning: The current table and all included data will be deleted (DROP TABLE)!'))
                        ->visible(fn ($get) => $get('table_exists'))
                        ->onColor('danger')
                        ->default(true)
                        ->live(),

                    Hidden::make('table_exists')
                        ->default(false),

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
                        ->default(false),

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
                                            'foreignUld' => 'Foreign ULID (Relation)',
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
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->collapsible()
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
                ->schema([
                    TextInput::make('model_name')
                        ->label(__('Model Name'))
                        ->helperText(__('Automatically generated from the table name, but can be modified.'))
                        ->live(onBlur: true)
                        ->required(),

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
                ]),
        ];
    }

    protected static function reviewStep(): array
    {
        return [
            Wizard\Step::make('Review')
                ->description(__('Preview of the files'))
                ->schema([
                    Toggle::make('run_migration')
                        ->label(__('Run migration immediately'))
                        ->helperText(__('If enabled, the table will be created in the database immediately after generating the files.'))
                        ->default(true)
                        ->live(),

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

                                                // Use relaxed constructor (shouldValidate: false)
                                                $blueprint = BlueprintData::fromArray($data, shouldValidate: false);

                                                return FactoryGenerator::make()->preview($blueprint);
                                            } catch (\Throwable $e) {
                                                // Catch mapping errors or missing properties
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
                                ->schema([
                                    TextEntry::make('resource_preview')
                                        ->state(fn ($get) => FilamentResourceGenerator::make()->preview(BlueprintData::fromArray($get(''))))
                                        ->formatStateUsing(fn ($state) => view('architect::components.code-preview', ['code' => $state]))
                                        ->html(),
                                ]),
                        ]),
                ]),
        ];
    }
}
