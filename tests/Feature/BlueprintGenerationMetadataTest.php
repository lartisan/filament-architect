<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\Support\BlueprintGenerationService;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

afterEach(function () {
    foreach (File::glob(database_path('migrations/*_legacy_projects_table.php')) as $migration) {
        File::delete($migration);
    }

    DB::table('migrations')
        ->where('migration', 'like', '%_legacy_projects_table')
        ->delete();

    DB::table('architect_blueprint_revisions')->delete();
    DB::table('architect_blueprints')->delete();

    if (Schema::hasTable('legacy_projects')) {
        Schema::drop('legacy_projects');
    }
});

it('stores generated migration metadata on both the saved blueprint and its latest revision', function () {
    $blueprintData = BlueprintData::fromArray([
        'table_name' => 'legacy_projects',
        'model_name' => 'LegacyProject',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'gen_factory' => false,
        'gen_seeder' => false,
        'gen_resource' => false,
        'run_migration' => false,
        'meta' => [
            'source' => 'architect-pro.reverse-engineering',
        ],
    ]);

    app(BlueprintGenerationService::class)->generate($blueprintData);

    $blueprint = Blueprint::query()
        ->where('table_name', 'legacy_projects')
        ->with('latestRevision')
        ->firstOrFail();

    $latestRevision = $blueprint->latestRevision;
    $generatedMigration = $blueprint->meta['generated_migration'] ?? null;
    $revisionMigration = $latestRevision?->meta['generated_migration'] ?? null;
    $formData = $blueprint->toFormData();

    expect($generatedMigration)->toBeArray()
        ->and($generatedMigration['generated'] ?? null)->toBeTrue()
        ->and($generatedMigration['path'] ?? null)->toContain('legacy_projects_table.php')
        ->and($generatedMigration['file_name'] ?? null)->toContain('legacy_projects_table.php')
        ->and($generatedMigration['content'] ?? null)->toContain("Schema::create('legacy_projects', function (Blueprint \$table) {")
        ->and($generatedMigration['preview'] ?? null)->toContain("Schema::create('legacy_projects', function (Blueprint \$table) {")
        ->and($blueprint->meta['source'] ?? null)->toBe('architect-pro.reverse-engineering')
        ->and($latestRevision)->not->toBeNull()
        ->and($latestRevision?->meta['source'] ?? null)->toBe('architect-pro.reverse-engineering')
        ->and($revisionMigration)->toBeArray()
        ->and($revisionMigration['file_name'] ?? null)->toBe($generatedMigration['file_name'])
        ->and($formData['meta']['generated_migration']['file_name'] ?? null)->toBe($generatedMigration['file_name']);
});

