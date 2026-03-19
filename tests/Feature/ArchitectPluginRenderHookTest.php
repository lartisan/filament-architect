<?php

use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Lartisan\Architect\ArchitectPlugin;
use Lartisan\Architect\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('architect.show', true);
});

it('defaults to the global search position when the panel topbar is enabled', function () {
    $plugin = new ArchitectPlugin;

    $plugin->boot(new Panel);

    expect(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::BODY_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::SIDEBAR_NAV_END))->toBeFalse();
});

it('defaults to the sidebar navigation end when the panel topbar is hidden', function () {
    $plugin = new ArchitectPlugin;

    $plugin->boot((new Panel)->topbar(false));

    expect(FilamentView::hasRenderHook(PanelsRenderHook::SIDEBAR_NAV_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::BODY_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE))->toBeFalse();
});

it('keeps an explicitly configured render hook when the panel topbar is hidden', function () {
    $plugin = (new ArchitectPlugin)
        ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER);

    $plugin->boot((new Panel)->topbar(false));

    expect(FilamentView::hasRenderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::BODY_END))->toBeTrue()
        ->and(FilamentView::hasRenderHook(PanelsRenderHook::SIDEBAR_NAV_END))->toBeFalse();
});
