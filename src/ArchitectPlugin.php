<?php

namespace Lartisan\Architect;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

class ArchitectPlugin implements Plugin
{
    protected string $renderHook = PanelsRenderHook::GLOBAL_SEARCH_BEFORE;

    protected array $availableRenderHooks = [
        PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
        PanelsRenderHook::GLOBAL_SEARCH_AFTER,
        PanelsRenderHook::USER_MENU_AFTER,
    ];

    protected bool $isIconButton = false;

    protected string | array | null $actionColor = null;

    public function getId(): string
    {
        return 'architect';
    }

    public function renderHook(string $hook): static
    {
        if ($this->isAllowedRenderHool($hook)) {
            $this->renderHook = $hook;
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

    public function actionColor(string | array | null $color): static
    {
        $this->actionColor = $color;

        return $this;
    }

    public function getActionColor(): string | array | null
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

        $this->registerArchitectAction();
    }

    protected function registerArchitectAction(): void
    {
        FilamentView::registerRenderHook(
            $this->renderHook,
            fn (): string => Blade::render(
                "@livewire('architect-wizard', ['isIconButton' => \$isIconButton, 'actionColor' => \$actionColor])",
                [
                    'isIconButton' => $this->isIconButton,
                    'actionColor' => $this->getActionColor(),
                ],
            ),
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
}
