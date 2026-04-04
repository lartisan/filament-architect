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
        // Allow registered hooks to abort generation before any state is mutated
        // (e.g. lock enforcement). Hooks throw to abort; any exception propagates up.
        app(BlueprintGenerationHookRegistry::class)->runBeforeGenerate($blueprintData);

        $plan = app(RegenerationPlanner::class)->plan($blueprintData);

        if ($plan->hasBlockingSchemaChanges()) {
            $blockingColumns = collect($plan->getBlockingSchemaChanges())
                ->map(fn (PlannedSchemaOperation $operation) => Str::after($operation->description, 'Add column '))
                ->all();

            throw InvalidBlueprintException::unsafeRequiredColumnAddition($blueprintData->tableName, $blockingColumns);
        }

        $migrationGenerator = MigrationGenerator::make();
        $migrationPreview = $migrationGenerator->preview($blueprintData);

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

        $generatedMigrationPath = $migrationGenerator->generate($blueprintData);
        $generatedMigrationMeta = $this->buildGeneratedMigrationMeta($generatedMigrationPath, $migrationPreview);

        $storedBlueprint->forceFill([
            'meta' => array_merge($storedBlueprint->meta ?? [], $generatedMigrationMeta),
        ])->save();

        ModelGenerator::make()->generate($blueprintData);

        if ($blueprintData->generateFactory) {
            FactoryGenerator::make()->generate($blueprintData);
        }

        if ($blueprintData->generateSeeder) {
            SeederGenerator::make()->generate($blueprintData);
        }

        $detectedLegacyFiles = [];

        if ($blueprintData->generateResource) {
            FilamentResourceGenerator::make()->generate($blueprintData);

            $detectedLegacyFiles = FilamentResourceGenerator::detectLegacyV3Artifacts($blueprintData->modelName);
        }

        $storedBlueprint->recordRevision($blueprintData, [
            'source' => $blueprintData->meta['source'] ?? 'architect',
            'generated_at' => now()->toIso8601String(),
            'generation_mode' => $blueprintData->generationMode->value,
            ...$generatedMigrationMeta,
        ]);

        $shouldRunMigration = $blueprintData->runMigration
            && (! $plan->hasDeferredRiskySchemaChanges() || ($blueprintData->generationMode->shouldReplaceExistingArtifacts() && $blueprintData->overwriteTable));

        if ($shouldRunMigration) {
            Artisan::call('migrate', ['--force' => true]);
        }

        app(BlueprintGenerationHookRegistry::class)->runAfterGenerate(
            $storedBlueprint->fresh(['latestRevision']) ?? $storedBlueprint,
            $blueprintData,
            $plan,
            $shouldRunMigration,
        );

        return [
            'plan' => $plan,
            'shouldRunMigration' => $shouldRunMigration,
            'detectedLegacyFiles' => $detectedLegacyFiles,
        ];
    }

    /**
     * @return array{generated_migration: array{generated: bool, path: string|null, file_name: string|null, content: string|null, preview: string|null}}
     */
    protected function buildGeneratedMigrationMeta(string $generatedMigrationPath, ?string $migrationPreview = null): array
    {
        $relativePath = null;
        $fileName = null;
        $content = null;

        if ($generatedMigrationPath !== '') {
            $relativePath = Str::after($generatedMigrationPath, base_path().DIRECTORY_SEPARATOR);
            $fileName = basename($generatedMigrationPath);
            $content = File::exists($generatedMigrationPath)
                ? File::get($generatedMigrationPath)
                : null;
        }

        return [
            'generated_migration' => [
                'generated' => $generatedMigrationPath !== '',
                'path' => $relativePath,
                'file_name' => $fileName,
                'content' => $content,
                'preview' => $migrationPreview,
            ],
        ];
    }
}
