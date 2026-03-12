<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lartisan\Architect\Exceptions\InvalidBlueprintException;
use Lartisan\Architect\Generators\FactoryGenerator;
use Lartisan\Architect\Generators\FilamentResourceGenerator;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Generators\SeederGenerator;
use Lartisan\Architect\Models\Blueprint as ArchitectBlueprint;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\PlannedSchemaOperation;
use Lartisan\Architect\ValueObjects\RegenerationPlan;

class BlueprintGenerationService
{
    /**
     * @return array{plan: RegenerationPlan, shouldRunMigration: bool}
     */
    public function generate(BlueprintData $blueprintData): array
    {
        $plan = app(RegenerationPlanner::class)->plan($blueprintData);

        if ($plan->hasBlockingSchemaChanges()) {
            $blockingColumns = collect($plan->getBlockingSchemaChanges())
                ->map(fn (PlannedSchemaOperation $operation) => Str::after($operation->description, 'Add column '))
                ->all();

            throw InvalidBlueprintException::unsafeRequiredColumnAddition($blueprintData->tableName, $blockingColumns);
        }

        $storedBlueprint = ArchitectBlueprint::updateOrCreate(
            ['table_name' => $blueprintData->tableName],
            $blueprintData->toFormData()
        );

        if ($blueprintData->generationMode->shouldReplaceExistingArtifacts() && $blueprintData->overwriteTable) {
            Schema::dropIfExists($blueprintData->tableName);

            $migrationFiles = glob(database_path('migrations/*_'.$blueprintData->tableName.'_table.php'));
            foreach ($migrationFiles as $file) {
                if (File::exists($file)) {
                    File::delete($file);
                }
            }

            DB::table('migrations')
                ->where('migration', 'like', '%_'.$blueprintData->tableName.'_table')
                ->delete();
        }

        MigrationGenerator::make()->generate($blueprintData);
        ModelGenerator::make()->generate($blueprintData);

        if ($blueprintData->generateFactory) {
            FactoryGenerator::make()->generate($blueprintData);
        }

        if ($blueprintData->generateSeeder) {
            SeederGenerator::make()->generate($blueprintData);
        }

        if ($blueprintData->generateResource) {
            FilamentResourceGenerator::make()->generate($blueprintData);
        }

        $storedBlueprint->recordRevision($blueprintData);

        $shouldRunMigration = $blueprintData->runMigration
            && (! $plan->hasDeferredRiskySchemaChanges() || ($blueprintData->generationMode->shouldReplaceExistingArtifacts() && $blueprintData->overwriteTable));

        if ($shouldRunMigration) {
            Artisan::call('migrate', ['--force' => true]);
        }

        return [
            'plan' => $plan,
            'shouldRunMigration' => $shouldRunMigration,
        ];
    }
}
