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
use Filament\Tables\Columns\Layout\Panel;
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
        return $table
            ->query(Blueprint::query()->latest())
            ->striped()
            ->columns($this->getTableColumns())
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

    /**
     * @return array<int, TextColumn|Panel>
     */
    protected function getTableColumns(): array
    {
        $columns = [
            TextColumn::make('table_name')->label(__('Table'))->searchable(),
            TextColumn::make('model_name')->label(__('Model')),
            TextColumn::make('created_at')->dateTime()->label(__('Created At')),
        ];

        $collapsibleContent = app(ArchitectUiExtensionRegistry::class)->blueprintsTableCollapsibleContent();

        if ($collapsibleContent === []) {
            return $columns;
        }

        $columns[] = Panel::make($collapsibleContent)
            ->collapsed();

        return $columns;
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
