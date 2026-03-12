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
use Livewire\Attributes\On;
use Livewire\Component;

class ArchitectWizard extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public bool $isIconButton = false;

    public string | array | null $actionColor = null;

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

    #[On('load-blueprint')]
    public function loadBlueprint(int $id): void
    {
        $blueprint = ArchitectBlueprint::find($id);

        if ($blueprint) {
            $data = $blueprint->toFormData();

            $this->getMountedActionSchema()->fill($data);

            Notification::make()
                ->title(__('Data was loaded!'))
                ->success()
                ->send();
        }
    }

    public function deleteBlueprint(int $id): void
    {
        ArchitectBlueprint::destroy($id);

        Notification::make()
            ->title('Blueprint deleted!')
            ->success()
            ->send();
    }
}
