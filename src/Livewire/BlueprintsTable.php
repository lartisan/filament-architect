<?php

namespace Lartisan\Architect\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\Support\ArchitectUiExtensionRegistry;
use Lartisan\Architect\Support\BlueprintDeletionService;
use Livewire\Attributes\On;
use Livewire\Component;

class BlueprintsTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        $extensionRecordActions = app(ArchitectUiExtensionRegistry::class)->blueprintsTableRecordActions();

        return $table
            ->query(Blueprint::query()->latest())
            ->columns($this->getTableColumns())
            ->recordActions([
                ...$extensionRecordActions,

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

    /**
     * @return array<int, TextColumn|IconColumn>
     */
    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('table_name')
                ->label(__('Table'))
                ->searchable(),
            TextColumn::make('model_name')
                ->label(__('Model'))
                ->badge()
                ->color('gray'),
            TextColumn::make('columns_count')
                ->label(__('Columns'))
                ->badge()
                ->color('primary')
                ->state(fn (Blueprint $record): int => count($record->columns ?? [])),
            IconColumn::make('soft_deletes')
                ->label(__('Soft Deletes'))
                ->boolean(),
            TextColumn::make('created_at')
                ->label(__('Created At'))
                ->dateTime()
                ->sortable(),
        ];
    }

    public function activateFirstTab(): void
    {
        $this->dispatch('activate-first-tab');
    }

    #[On('architect-blueprint-updated')]
    public function refreshBlueprintTable(): void {}

    public function render()
    {
        return view('architect::livewire.blueprints-table');
    }

    public function deleteBlueprint(Blueprint $record): void
    {
        app(BlueprintDeletionService::class)->deleteBlueprintAndArtifacts($record);

        $this->redirect($this->getPanelRootUrl(), navigate: true);
    }

    private function getPanelRootUrl(): string
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $path = trim($panel?->getPath() ?? '', '/');

        return $path === ''
            ? url('/')
            : url('/'.$path);
    }
}
