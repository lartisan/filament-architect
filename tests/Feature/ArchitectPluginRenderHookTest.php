<?php

use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Lartisan\Architect\ArchitectPlugin;
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
