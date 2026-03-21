<?php

namespace Lartisan\Architect;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Lartisan\Architect\Support\ArchitectBlockRegistry;
use Lartisan\Architect\Support\ArchitectCapabilityRegistry;
use Lartisan\Architect\Support\ArchitectUiExtensionRegistry;
use Lartisan\Architect\Support\BlueprintGenerationHookRegistry;

class ArchitectPlugin implements Plugin
{
    protected string $renderHook = PanelsRenderHook::GLOBAL_SEARCH_BEFORE;

    protected bool $hasCustomRenderHook = false;

    protected array $availableRenderHooks = [
        PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
        PanelsRenderHook::GLOBAL_SEARCH_AFTER,
        PanelsRenderHook::USER_MENU_AFTER,
        PanelsRenderHook::SIDEBAR_NAV_START,
        PanelsRenderHook::SIDEBAR_NAV_END,
        PanelsRenderHook::SIDEBAR_FOOTER,
    ];

    protected bool $isIconButton = false;

    protected string|array|null $actionColor = null;

    public function getId(): string
    {
        return 'architect';
    }

    public function renderHook(string $hook): static
    {
        if ($this->isAllowedRenderHool($hook)) {
            $this->renderHook = $hook;
            $this->hasCustomRenderHook = true;
        }

        return $this;
    }

    protected function isAllowedRenderHool(string $hook): bool
    {
        return in_array($hook, $this->availableRenderHooks);
    }

    public function iconButton(bool $condition = true): static
    {
        $this->isIconButton = $condition;

        return $this;
    }

    public function actionColor(string|array|null $color): static
    {
        $this->actionColor = $color;

        return $this;
    }

    public function getActionColor(): string|array|null
    {
        return $this->actionColor;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->pages([
                // Pages\ArchitectPage::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        if (! config('architect.show', ! app()->isProduction())) {
            return;
        }

        if (! $this->hasCustomRenderHook) {
            $this->renderHook = $panel->hasTopbar()
                ? PanelsRenderHook::GLOBAL_SEARCH_BEFORE
                : PanelsRenderHook::SIDEBAR_NAV_END;
        }

        $this->registerArchitectTrigger();
        $this->registerArchitectModalHost();
    }

    protected function registerArchitectTrigger(): void
    {
        FilamentView::registerRenderHook(
            $this->renderHook,
            fn (): string => Blade::render(
                "@livewire('architect-trigger', ['isIconButton' => \$isIconButton, 'actionColor' => \$actionColor])",
                [
                    'isIconButton' => $this->isIconButton,
                    'actionColor' => $this->getActionColor(),
                ],
            ),
        );
    }

    protected function registerArchitectModalHost(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => Blade::render("@livewire('architect-wizard')"),
        );
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public static function capabilities(): ArchitectCapabilityRegistry
    {
        return app(ArchitectCapabilityRegistry::class);
    }

    public static function blocks(): ArchitectBlockRegistry
    {
        return app(ArchitectBlockRegistry::class);
    }

    public static function uiExtensions(): ArchitectUiExtensionRegistry
    {
        return app(ArchitectUiExtensionRegistry::class);
    }

    public static function generationHooks(): BlueprintGenerationHookRegistry
    {
        return app(BlueprintGenerationHookRegistry::class);
    }
}
