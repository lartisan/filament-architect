<?php

namespace Lartisan\Architect\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Lartisan\Architect\Actions\ArchitectAction;
use Lartisan\Architect\Models\Blueprint as ArchitectBlueprint;
use Lartisan\Architect\Support\ArchitectMigrationStatus;
use Lartisan\Architect\Support\BlueprintDeletionService;
use Livewire\Attributes\On;
use Livewire\Component;

class ArchitectWizard extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public bool $isIconButton = false;

    public string|array|null $actionColor = null;

    public function openArchitectAction(): Action
    {
        $action = ArchitectAction::make();

        if ($this->actionColor !== null) {
            $action->color($this->actionColor);
        }

        if ($this->isIconButton) {
            $action->iconButton()
                ->tooltip(__('Open Filament Architect'));
        } else {
            $action->extraAttributes([
                'class' => 'w-full justify-start',
            ]);
        }

        return $action;
    }

    public function render()
    {
        return view('architect::architect-wizard');
    }

    #[On('open-architect-wizard')]
    public function openArchitect(): void
    {
        if (filled($this->mountedActions)) {
            return;
        }

        if (! $this->ensureMigrationsAreReady()) {
            return;
        }

        $this->mountAction('openArchitect');
    }

    #[On('load-blueprint')]
    public function loadBlueprint(int $id): void
    {
        if (! $this->ensureMigrationsAreReady()) {
            return;
        }

        $blueprint = ArchitectBlueprint::find($id);

        if ($blueprint) {
            $data = $blueprint->toFormData();

            $this->openArchitect();
            $this->getMountedActionSchema()->fill($data);

            Notification::make()
                ->title(__('Data was loaded!'))
                ->success()
                ->send();
        }
    }

    #[On('load-blueprint-data')]
    public function loadBlueprintData(array $data): void
    {
        if (! $this->ensureMigrationsAreReady()) {
            return;
        }

        $this->openArchitect();
        $this->getMountedActionSchema()->fill($data);

        Notification::make()
            ->title(__('Blueprint revision loaded!'))
            ->success()
            ->send();
    }

    public function deleteBlueprint(int $id): void
    {
        if (! $this->ensureMigrationsAreReady()) {
            return;
        }

        $blueprint = ArchitectBlueprint::find($id);

        if ($blueprint === null) {
            return;
        }

        app(BlueprintDeletionService::class)->deleteSnapshotOnly($blueprint);

        Notification::make()
            ->title('Blueprint deleted!')
            ->success()
            ->send();
    }

    protected function ensureMigrationsAreReady(): bool
    {
        if (app(ArchitectMigrationStatus::class)->isReady()) {
            return true;
        }

        $this->notifyMissingMigrations();

        return false;
    }

    protected function notifyMissingMigrations(): void
    {
        Notification::make()
            ->title(__('Architect migrations are missing'))
            ->body(__('Run `php artisan architect:install` (recommended), then `php artisan migrate` before using Filament Architect. If you are upgrading manually, publish the `architect-migrations` tag and run `php artisan migrate`.'))
            ->warning()
            ->persistent()
            ->send();
    }
}
