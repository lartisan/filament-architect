<?php

namespace Lartisan\Architect;

use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ArchitectServiceProvider extends PackageServiceProvider
{
    public static string $name = 'architect';

    public static string $viewNamespace = 'architect';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('architect')
            ->hasViews('architect')
            ->hasMigration('create_architect_blueprints_table')
            ->hasAssets()
            ->hasCommands(\Lartisan\Architect\Commands\InstallCommand::class);
    }

    public function packageRegistered(): void
    {
        //
    }

    public function packageBooted(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'architect');

        \Livewire\Livewire::component('architect-wizard', \Lartisan\Architect\Livewire\ArchitectWizard::class);

        if (class_exists(FilamentAsset::class)) {
            FilamentAsset::register(
                $this->getAssets(),
                $this->getAssetPackageName()
            );
        }

        if (class_exists(FilamentIcon::class)) {
            FilamentIcon::register($this->getIcons());
        }

        Blade::component('architect-code-preview', \Lartisan\Architect\View\Components\CodePreview::class);
    }

    protected function getAssets(): array
    {
        return [
            \Filament\Support\Assets\Css::make('prism-tomorrow', __DIR__.'/../resources/dist/prism-tomorrow.min.css'),
            \Filament\Support\Assets\Js::make('prism-core', __DIR__.'/../resources/dist/prism.min.js'),
            \Filament\Support\Assets\Js::make('prism-markup-templating', __DIR__.'/../resources/dist/prism-markup-templating.min.js'),
            \Filament\Support\Assets\Js::make('prism-php', __DIR__.'/../resources/dist/prism-php.min.js'),
            \Filament\Support\Assets\Js::make('prism-init', __DIR__.'/../resources/dist/prism-php.min.js')->html(<<<'JS'
                <script data-navigate-track>
                    (function () {
                        function highlightAll() {
                            if (typeof Prism !== 'undefined' && Prism.languages.php) {
                                Prism.highlightAll();
                            }
                        }

                        document.addEventListener('livewire:init', function () {
                            Livewire.hook('commit', ({ succeed }) => {
                                succeed(() => queueMicrotask(highlightAll));
                            });
                        });
                    })();
                </script>
            JS),
        ];
    }

    protected function getAssetPackageName(): ?string
    {
        return 'lartisan/filament-architect';
    }

    protected function getIcons(): array
    {
        return [];
    }

    protected function getCommands(): array
    {
        return [
            \Lartisan\Architect\Commands\InstallCommand::class,
        ];
    }

    protected function getMigrations(): array
    {
        return [];
    }
}
