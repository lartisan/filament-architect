<div class="flex w-full">
    @if ($isIconButton)
        <x-filament::icon-button
            :color="$this->getTriggerColor()"
            :icon="$this->getTriggerIcon()"
            :label="__('Open Filament Architect')"
            :tooltip="__('Open Filament Architect')"
            wire:click="openArchitect"
        />
    @else
        <x-filament::button
            :color="$this->getTriggerColor()"
            :icon="$this->getTriggerIcon()"
            class="w-full justify-start"
            wire:click="openArchitect"
        >
            {{ __('Architect') }}
        </x-filament::button>
    @endif
</div>

