<?php

use Filament\Schemas\Components\Tabs as TabsComponent;
use Filament\Schemas\Schema;
use Lartisan\Architect\Actions\ArchitectAction;
use Lartisan\Architect\Livewire\ArchitectWizard;
use Lartisan\Architect\Tests\TestCase;

uses(TestCase::class);

it('uses explicit tab keys for first-tab activation', function () {
    $livewire = app(ArchitectWizard::class);
    $action = ArchitectAction::make();
    $schema = $action->getSchema(Schema::make($livewire));

    expect($schema)->not->toBeNull();

    $tabs = collect($schema->getComponents())
        ->first(fn ($component) => $component instanceof TabsComponent);

    expect($tabs)->toBeInstanceOf(TabsComponent::class);

    $tabComponents = array_values($tabs->getChildSchema()->getComponents());

    expect($tabComponents[0]->getKey(isAbsolute: false))->toBe('architect-create-edit-tab')
        ->and($tabComponents[1]->getKey(isAbsolute: false))->toBe('architect-existing-resources-tab')
        ->and($tabs->getExtraAttributes()['x-on:activate-first-tab.window'] ?? null)->toBe("\$data.tab = 'architect-create-edit-tab';");
});
