<?php

namespace Lartisan\Architect\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InstallCommand extends Command
{
    protected $signature = 'architect:install';

    protected $description = 'Install Filament Architect (first-time setup)';

    public function handle(): int
    {
        if (Schema::hasTable('architect_blueprints')) {
            $this->components->warn('Architect tables already exist.');
            $this->newLine();
            $this->line('  It looks like Filament Architect is already installed.');
            $this->line('  If you are upgrading from <comment>v0.2.x</comment>, run:');
            $this->newLine();
            $this->line('    <info>php artisan architect:upgrade</info>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->components->info('Installing Filament Architect...');
        $this->newLine();

        $this->publishAssets();
        $this->publishMigrations();
        $this->runMigrations();
        $this->registerComposerHook();

        $this->newLine();
        $this->components->info('Filament Architect installed successfully!');

        return self::SUCCESS;
    }

    private function publishAssets(): void
    {
        $this->components->task('Publishing assets', function (): void {
            $this->callSilently('filament:assets');
        });
    }

    private function publishMigrations(): void
    {
        $this->components->task('Publishing migrations', function (): void {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Lartisan\\Architect\\ArchitectServiceProvider',
                '--tag' => 'architect-migrations',
            ]);
        });
    }

    private function runMigrations(): void
    {
        $this->components->task('Running migrations', function (): void {
            $this->callSilently('migrate', ['--force' => true]);
        });
    }

    private function registerComposerHook(): void
    {
        $path = base_path('composer.json');

        if (! file_exists($path)) {
            return;
        }

        $configuration = json_decode(file_get_contents($path), associative: true);

        $command = '@php artisan filament:assets';

        if (in_array($command, $configuration['scripts']['post-autoload-dump'] ?? [])) {
            $this->components->twoColumnDetail('Composer hook', '<fg=green;options=bold>Already registered</>');

            return;
        }

        $configuration['scripts']['post-autoload-dump'][] = $command;

        file_put_contents($path, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->components->twoColumnDetail('Composer hook', '<fg=green;options=bold>Registered</>');
    }
}
