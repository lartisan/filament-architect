<?php

use Filament\Actions\Action;
use Lartisan\Architect\Actions\ArchitectAction;
use Lartisan\Architect\ArchitectPlugin;
use Lartisan\Architect\Livewire\ArchitectWizard;
use Lartisan\Architect\Tests\TestCase;

uses(TestCase::class);

it('places the version badge in the modal footer actions', function () {
    $livewire = app(ArchitectWizard::class);
    $action = ArchitectAction::make()->livewire($livewire);

    $footerActions = collect($action->getModalFooterActions())
        ->keyBy(fn (Action $a) => $a->getName());

    expect($footerActions)
        ->toHaveKey('cancel')
        ->toHaveKey('architect_version_badge');

    expect($footerActions->get('architect_version_badge')->getLabel())
        ->toContain('Plugin version')
        ->toContain(ArchitectPlugin::version());
});
