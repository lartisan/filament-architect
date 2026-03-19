<?php

namespace Lartisan\Architect\Livewire;

use Filament\Support\Icons\Heroicon;
use Livewire\Component;

class ArchitectTrigger extends Component
{
    public bool $isIconButton = false;

    public string|array|null $actionColor = null;

    public function openArchitect(): void
    {
        $this->dispatch('open-architect-wizard');
    }

    public function getTriggerColor(): string|array
    {
        return $this->actionColor ?? 'primary';
    }

    public function getTriggerIcon(): Heroicon
    {
        return Heroicon::Square3Stack3d;
    }

    public function render()
    {
        return view('architect::architect-trigger');
    }
}
