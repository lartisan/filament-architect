<?php

namespace Lartisan\Architect\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\Support\GenerationPathResolver;
use Livewire\Component;

class BlueprintsTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(Blueprint::query()->latest())
            ->columns([
                TextColumn::make('table_name')->label(__('Table'))->searchable(),
                TextColumn::make('model_name')->label(__('Model')),
                TextColumn::make('created_at')->dateTime()->label(__('Created At')),
            ])
            ->recordActions([
                Action::make('load')
                    ->label(__('Load'))
                    ->icon('heroicon-m-arrow-path')
                    ->color('success')
                    ->action(function ($record) {
                        $this->dispatch('load-blueprint', id: $record->id)
                            ->to(ArchitectWizard::class);

                        $this->activateFirstTab();

                        Notification::make()
                            ->title(__('Blueprint loaded: :table', ['table' => $record->table_name]))
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalIcon(Heroicon::ShieldExclamation)
                    ->modalDescription('Are you sure you want to delete this blueprint?')
                    ->modalContent(view('architect::blueprint-delete'))
                    ->action(fn (Blueprint $record) => $this->deleteBlueprint($record))
                    ->successNotificationTitle(__('Resource and associated files deleted successfully')),
            ])
            ->emptyStateHeading(__('No blueprints yet, create one!'))
            ->emptyStateActions([
                Action::make('create_blueprint')
                    ->action(fn () => $this->activateFirstTab()),
            ]);
    }

    public function activateFirstTab(): void
    {
        $this->dispatch('activate-first-tab');
    }

    public function render()
    {
        return view('architect::livewire.blueprints-table');
    }

    public function deleteBlueprint(Blueprint $record): void
    {
        $modelName = $record->model_name;
        $tableName = $record->table_name;

        Schema::dropIfExists($tableName);
        DB::table('migrations')
            ->where('migration', 'like', "%_{$tableName}_table")
            ->delete();

        $filesToDelete = [
            GenerationPathResolver::model($modelName),
            GenerationPathResolver::factory("{$modelName}Factory"),
            GenerationPathResolver::seeder("{$modelName}Seeder"),
            GenerationPathResolver::resource("{$modelName}Resource"),
        ];

        $resourceDirectory = GenerationPathResolver::resourceDirectory("{$modelName}Resource");

        foreach ($filesToDelete as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        if (File::isDirectory($resourceDirectory)) {
            File::deleteDirectory($resourceDirectory);
        }

        $migrationFiles = File::glob(database_path("migrations/*_{$tableName}_table.php"));
        foreach ($migrationFiles as $migration) {
            File::delete($migration);
        }

        $record->delete();
    }
}
