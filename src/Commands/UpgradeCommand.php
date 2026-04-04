<?php

namespace Lartisan\Architect\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\ValueObjects\BlueprintData;

class UpgradeCommand extends Command
{
    protected $signature = 'architect:upgrade
                            {--dry-run : Preview which blueprints would be backfilled without writing anything}';

    protected $description = 'Upgrade Filament Architect from any pre-1.0.0 release to v1.0.0 (run migrations & backfill revisions)';

    public function handle(): int
    {
        $this->components->info('Upgrading Filament Architect...');
        $this->newLine();

        $this->publishMigrations();
        $this->runMigrations();

        if (! $this->tablesExist()) {
            return self::FAILURE;
        }

        return $this->backfillRevisions();
    }

    private function publishMigrations(): void
    {
        // Check for the v1.0.0 revisions migration specifically — v0.2.x users will
        // already have the blueprints migration but NOT the new revisions' migration.
        $alreadyPublished = ! empty(glob(database_path('migrations/*create_architect_blueprint_revisions_table.php')));

        if ($alreadyPublished) {
            $this->components->twoColumnDetail('Architect migrations', '<fg=green;options=bold>Already published</>');

            return;
        }

        $this->callSilently('vendor:publish', [
            '--provider' => 'Lartisan\\Architect\\ArchitectServiceProvider',
            '--tag' => 'architect-migrations',
        ]);

        $this->components->twoColumnDetail('Architect migrations', '<fg=green;options=bold>Published</>');
    }

    private function runMigrations(): void
    {
        $this->components->task('Running migrations', function (): void {
            $this->callSilently('migrate', ['--force' => true]);
        });

        $this->newLine();
    }

    private function backfillRevisions(): int
    {
        $blueprints = Blueprint::query()
            ->whereDoesntHave('revisions')
            ->get();

        if ($blueprints->isEmpty()) {
            $this->components->twoColumnDetail('Blueprint revisions', '<fg=green;options=bold>All up to date</>');

            return self::SUCCESS;
        }

        $isDryRun = $this->option('dry-run');

        $this->line(sprintf(
            '  %s Found <comment>%d</comment> blueprint(s) without revisions%s.',
            $isDryRun ? '[dry-run]' : '•',
            $blueprints->count(),
            $isDryRun ? ' (no changes will be written)' : '',
        ));

        $this->newLine();

        $backfilled = 0;

        foreach ($blueprints as $blueprint) {
            $this->line(sprintf(
                '  %s <info>%s</info> (table: <comment>%s</comment>)',
                $isDryRun ? '[dry-run]' : '→',
                $blueprint->model_name,
                $blueprint->table_name,
            ));

            if (! $isDryRun) {
                $blueprintData = BlueprintData::fromArray(
                    $blueprint->toFormData(),
                    shouldValidate: false,
                );

                $blueprint->recordRevision($blueprintData, [
                    'backfilled_by_upgrade' => true,
                    'backfilled_at' => now()->toIso8601String(),
                ]);

                $backfilled++;
            }
        }

        $this->newLine();

        if ($isDryRun) {
            $this->components->warn(sprintf('[dry-run] %d blueprint(s) would be backfilled. Run without --dry-run to apply.', $blueprints->count()));
        } else {
            $this->components->info(sprintf('Successfully backfilled %d blueprint revision(s).', $backfilled));
        }

        return self::SUCCESS;
    }

    private function tablesExist(): bool
    {
        $missing = array_filter(
            ['architect_blueprints', 'architect_blueprint_revisions'],
            fn (string $table): bool => ! Schema::hasTable($table),
        );

        if (empty($missing)) {
            return true;
        }

        $this->error('Required tables are still missing after migration: '.implode(', ', $missing));
        $this->line('Check your database connection and migration output, then try again.');

        return false;
    }
}
