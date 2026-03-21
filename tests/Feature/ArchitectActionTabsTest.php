<?php

use Filament\Schemas\Components\Tabs as TabsComponent;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Wizard as WizardComponent;
use Filament\Schemas\Components\Wizard\Step as WizardStep;
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

    /** @var TabsComponent $tabs */

    $tabComponents = array_values($tabs->getChildSchema()->getComponents());

    expect($tabComponents[0]->getKey(isAbsolute: false))->toBe('architect-create-edit-tab')
        ->and($tabComponents[1]->getKey(isAbsolute: false))->toBe('architect-existing-resources-tab')
        ->and($tabs->getExtraAttributes()['x-on:activate-first-tab.window'] ?? null)->toBe("\$data.tab = 'architect-create-edit-tab';");
});

it('keeps the review step limited to generated file previews', function () {
    $livewire = app(ArchitectWizard::class);
    $action = ArchitectAction::make();
    $schema = $action->getSchema(Schema::make($livewire));

    $tabs = collect($schema->getComponents())
        ->first(fn ($component) => $component instanceof TabsComponent);

    expect($tabs)->toBeInstanceOf(TabsComponent::class);

    /** @var TabsComponent $tabs */

    $createEditTab = array_values($tabs->getChildSchema()->getComponents())[0];
    $wizard = collect($createEditTab->getChildSchema()->getComponents())
        ->first(fn ($component) => $component instanceof WizardComponent);

    expect($wizard)->toBeInstanceOf(WizardComponent::class);

    /** @var WizardComponent $wizard */

    $steps = collect($wizard->getChildSchema()->getComponents())
        ->filter(fn ($component) => $component instanceof WizardStep)
        ->values();

    $eloquentStep = $steps->first(fn (WizardStep $step) => $step->getLabel() === 'Eloquent');
    $reviewStep = $steps->first(fn (WizardStep $step) => $step->getLabel() === 'Review');

    expect($eloquentStep)->toBeInstanceOf(WizardStep::class)
        ->and($reviewStep)->toBeInstanceOf(WizardStep::class)
        ->and($reviewStep->getDescription())->toBe('Preview the generated files');

    $eloquentNames = collect(flattenSchemaComponents($eloquentStep))
        ->map(fn ($component) => method_exists($component, 'getName') ? $component->getName() : null)
        ->filter()
        ->values()
        ->all();

    $reviewComponents = array_values($reviewStep->getChildSchema()->getComponents());
    $reviewNames = collect(flattenSchemaComponents($reviewStep))
        ->map(fn ($component) => method_exists($component, 'getName') ? $component->getName() : null)
        ->filter()
        ->values()
        ->all();

    expect($eloquentNames)->toContain('run_migration', 'allow_likely_renames', 'allow_destructive_changes')
        ->and($reviewComponents)->toHaveCount(1)
        ->and($reviewComponents[0])->toBeInstanceOf(TabsComponent::class)
        ->and($reviewNames)->not->toContain('regeneration_plan', 'run_migration', 'allow_likely_renames', 'allow_destructive_changes');
});

function flattenSchemaComponents(object $component): array
{
    $components = [$component];

    if (! method_exists($component, 'getChildSchema')) {
        return $components;
    }

    foreach ($component->getChildSchema()->getComponents(withHidden: true) as $childComponent) {
        $components = [...$components, ...flattenSchemaComponents($childComponent)];
    }

    return $components;
}

