<?php

use Filament\Actions\Action;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Lartisan\Architect\Actions\ArchitectAction;
use Lartisan\Architect\ArchitectPlugin;
use Lartisan\Architect\Livewire\ArchitectWizard;
use Lartisan\Architect\Tests\TestCase;

uses(TestCase::class);

it('defaults to the global search position when the panel topbar is enabled in non-production', function () {
    $plugin = new ArchitectPlugin;

    $plugin->boot(new Panel);

    expect(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::BODY_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::SIDEBAR_NAV_END))->toBeFalse();
});

it('defaults to the sidebar navigation end when the panel topbar is hidden', function () {
    config()->set('architect.show', true);

    $plugin = new ArchitectPlugin;

    $plugin->boot((new Panel)->topbar(false));

    expect(FilamentView::hasRenderHook(PanelsRenderHook::SIDEBAR_NAV_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::BODY_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE))->toBeFalse();
});

it('keeps an explicitly configured render hook when the panel topbar is hidden', function () {
    config()->set('architect.show', true);

    $plugin = (new ArchitectPlugin)
        ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER);

    $plugin->boot((new Panel)->topbar(false));

    expect(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::BODY_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::SIDEBAR_NAV_END))->toBeFalse();
});

it('does not register any architect hooks when the plugin is explicitly hidden', function () {
    config()->set('architect.show', false);

    $plugin = new ArchitectPlugin;

    $plugin->boot(new Panel);

    expect(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE))->toBeFalse()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::BODY_END))->toBeFalse()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::SIDEBAR_NAV_END))->toBeFalse();
});

it('registers the architect version action render hook for the wizard modal when supported', function () {
    if (! class_exists(\Filament\Actions\View\ActionsRenderHook::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('architect.show', true);

    $plugin = new ArchitectPlugin;

    $plugin->boot(new Panel);

    expect(FilamentView::hasRenderHook(\Filament\Actions\View\ActionsRenderHook::MODAL_SCHEMA_AFTER, ArchitectWizard::class))
        ->toBeTrue();

    $architectActionHook = (string) FilamentView::renderHook(
        \Filament\Actions\View\ActionsRenderHook::MODAL_SCHEMA_AFTER,
        ArchitectWizard::class,
        ['action' => ArchitectAction::make()],
    );

    $otherActionHook = (string) FilamentView::renderHook(
        \Filament\Actions\View\ActionsRenderHook::MODAL_SCHEMA_AFTER,
        ArchitectWizard::class,
        ['action' => Action::make('anotherAction')],
    );

    expect($architectActionHook)
        ->toContain('Plugin version')
        ->toContain(ArchitectPlugin::version())
        ->and($otherActionHook)
        ->toBe('');
});

