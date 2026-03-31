<?php

namespace Lartisan\Architect\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'architect:install';

    protected $description = 'Install Filament Architect';

    public function handle(): int
    {
        $this->info('Installing Filament Architect...');

        // Copy assets to public/ via Filament's asset pipeline
        $this->call('filament:assets');

        // Publish migrations
        $this->call('vendor:publish', [
            '--provider' => 'Lartisan\\Architect\\ArchitectServiceProvider',
            '--tag' => 'architect-migrations',
        ]);

        $this->newLine();
        $this->warn('IMPORTANT: Filament Architect requires database migrations.');
        $this->newLine();
        $this->line('  Run <info>php artisan architect:upgrade</info> to migrate and backfill revisions in one step.');
        $this->newLine();
        $this->line('  Or manually:');
        $this->line('    <info>php artisan migrate</info>');
        $this->line('    <info>php artisan architect:upgrade</info> (to backfill revisions for existing blueprints)');

        $this->registerUpgradeHook();

        $this->info('Filament Architect installed successfully!');

        return self::SUCCESS;
    }

    protected function registerUpgradeHook(): void
    {
        $path = base_path('composer.json');

        if (! file_exists($path)) {
            return;
        }

        $configuration = json_decode(file_get_contents($path), associative: true);

        $command = '@php artisan filament:assets';

        if (in_array($command, $configuration['scripts']['post-autoload-dump'] ?? [])) {
            return;
        }

        $configuration['scripts']['post-autoload-dump'][] = $command;

        file_put_contents($path, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->components->info('Registered `filament:assets` in composer.json post-autoload-dump.');
    }
}
