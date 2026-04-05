<?php

namespace Lartisan\Architect\Tests\Feature;

use Filament\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Generators\FactoryGenerator;
use Lartisan\Architect\Generators\FilamentResourceGenerator;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Generators\SeederGenerator;
use Lartisan\Architect\Livewire\BlueprintsTable;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\Support\ArchitectUiExtensionRegistry;
use Lartisan\Architect\Support\BlueprintDeletionService;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

beforeEach(function () {
    // Load Laravel migrations (users table, etc.)
    $this->loadLaravelMigrations();

    config()->set('architect.models_namespace', blueprintsTableModelsNamespace());
    config()->set('architect.factories_namespace', blueprintsTableFactoriesNamespace());
    config()->set('architect.seeders_namespace', blueprintsTableSeedersNamespace());
    config()->set('architect.resources_namespace', blueprintsTableResourcesNamespace());

    app(ArchitectUiExtensionRegistry::class)->flush();

    // Clean up any leftover files from previous tests
    cleanupTestFiles();
});

afterEach(function () {
    app(ArchitectUiExtensionRegistry::class)->flush();

    // Cleanup all generated files
    cleanupTestFiles();
});

function cleanupTestFiles(): void
{
    $models = ['Product', 'TestModel', 'Article'];
    $tables = ['products', 'test_models', 'articles'];

    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            Schema::drop($table);
        }

        DB::table('migrations')
            ->where('migration', 'like', "%_{$table}_table")
            ->delete();
    }

    foreach ($models as $model) {
        @unlink(GenerationPathResolver::model($model));
        @unlink(GenerationPathResolver::factory("{$model}Factory"));
        @unlink(GenerationPathResolver::seeder("{$model}Seeder"));
        @unlink(GenerationPathResolver::resource("{$model}Resource"));

        $resourceDir = GenerationPathResolver::resourceDirectory("{$model}Resource");
        if (File::isDirectory($resourceDir)) {
            File::deleteDirectory($resourceDir);
        }
    }

    foreach ([
        blueprintsTableModelsRoot(),
        blueprintsTableFactoriesRoot(),
        blueprintsTableSeedersRoot(),
        blueprintsTableResourcesRoot(),
    ] as $directory) {
        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    $migrations = File::glob(database_path('migrations/*.php'));
    foreach ($migrations as $migration) {
        if (preg_match('/_(create|sync)_(products|test_models|articles)_table\.php$/', $migration)) {
            @unlink($migration);
        }
    }
}

it('deletes blueprint and all associated files when delete action is called', function () {
    // Create blueprint data
    $blueprintData = BlueprintData::fromArray([
        'table_name' => 'products',
        'model_name' => 'Product',
        'columns' => [
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'price', 'type' => 'decimal'],
            ['name' => 'description', 'type' => 'text', 'is_nullable' => true],
        ],
        'soft_deletes' => true,
        'gen_factory' => true,
        'gen_seeder' => true,
        'gen_resource' => true,
    ]);

    // Generate all files
    $migrationPath = (new MigrationGenerator)->generate($blueprintData);
    $modelPath = (new ModelGenerator)->generate($blueprintData);
    $factoryPath = (new FactoryGenerator)->generate($blueprintData);
    $seederPath = (new SeederGenerator)->generate($blueprintData);
    $resourcePath = (new FilamentResourceGenerator)->generate($blueprintData);

    // Verify files were created
    expect(File::exists($migrationPath))->toBeTrue('Migration file should exist');
    expect(File::exists($modelPath))->toBeTrue('Model file should exist');
    expect(File::exists($factoryPath))->toBeTrue('Factory file should exist');
    expect(File::exists($seederPath))->toBeTrue('Seeder file should exist');
    expect(File::exists($resourcePath))->toBeTrue('Resource file should exist');

    // Run migration to create table
    migrateBlueprintsTableTestMigration($migrationPath);

    // Verify table was created
    expect(Schema::hasTable('products'))->toBeTrue('Table should exist in database');

    // Verify migration record exists
    $migrationRecord = DB::table('migrations')
        ->where('migration', 'like', '%_create_products_table')
        ->first();
    expect($migrationRecord)->not->toBeNull('Migration record should exist in migrations table');

    // Create blueprint record in database
    $blueprint = Blueprint::create([
        'table_name' => 'products',
        'model_name' => 'Product',
        'primary_key_type' => 'id',
        'columns' => $blueprintData->columns,
        'soft_deletes' => true,
    ]);

    // Verify resource pages exist
    $resourceDir = GenerationPathResolver::resourceDirectory('ProductResource');
    expect(File::exists("$resourceDir/Pages/ListProducts.php"))->toBeTrue('List page should exist');
    expect(File::exists("$resourceDir/Pages/CreateProduct.php"))->toBeTrue('Create page should exist');
    expect(File::exists("$resourceDir/Pages/EditProduct.php"))->toBeTrue('Edit page should exist');

    // Delete blueprint
    app(BlueprintDeletionService::class)->deleteBlueprintAndArtifacts($blueprint);

    // Verify blueprint record was deleted from database
    expect(Blueprint::find($blueprint->id))->toBeNull('Blueprint record should be deleted from database');

    // Verify table was dropped from database
    expect(Schema::hasTable('products'))->toBeFalse('Table should be dropped from database');

    // Verify migration record was deleted
    $migrationRecordAfter = DB::table('migrations')
        ->where('migration', 'like', '%_create_products_table')
        ->first();
    expect($migrationRecordAfter)->toBeNull('Migration record should be deleted from migrations table');

    // Verify all files were deleted
    expect(File::exists($modelPath))->toBeFalse('Model file should be deleted');
    expect(File::exists($factoryPath))->toBeFalse('Factory file should be deleted');
    expect(File::exists($seederPath))->toBeFalse('Seeder file should be deleted');
    expect(File::exists($resourcePath))->toBeFalse('Resource file should be deleted');

    // Verify migration file was deleted
    expect(File::exists($migrationPath))->toBeFalse('Migration file should be deleted');

    // Verify resource directory was deleted
    expect(File::isDirectory($resourceDir))->toBeFalse('Resource directory should be deleted');
});

it('handles deletion gracefully when some files do not exist', function () {
    // Create blueprint without generating all files
    $blueprint = Blueprint::create([
        'table_name' => 'test_models',
        'model_name' => 'TestModel',
        'primary_key_type' => 'id',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'soft_deletes' => false,
    ]);

    // Only create model and migration (not factory, seeder, or resource)
    $blueprintData = BlueprintData::fromArray([
        'table_name' => 'test_models',
        'model_name' => 'TestModel',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'gen_factory' => false,
        'gen_seeder' => false,
        'gen_resource' => false,
    ]);

    $migrationPath = (new MigrationGenerator)->generate($blueprintData);
    $modelPath = (new ModelGenerator)->generate($blueprintData);

    // Run migration
    migrateBlueprintsTableTestMigration($migrationPath);

    // Verify initial state
    expect(File::exists($modelPath))->toBeTrue();
    expect(File::exists($migrationPath))->toBeTrue();
    expect(Schema::hasTable('test_models'))->toBeTrue();

    // Delete blueprint - should not throw errors even though factory/seeder/resource don't exist
    app(BlueprintDeletionService::class)->deleteBlueprintAndArtifacts($blueprint);

    // Verify cleanup
    expect(Blueprint::find($blueprint->id))->toBeNull();
    expect(Schema::hasTable('test_models'))->toBeFalse();
    expect(File::exists($modelPath))->toBeFalse();
    expect(File::exists($migrationPath))->toBeFalse();
});

it('deletes multiple blueprints independently', function () {
    // Create first blueprint with files
    $blueprint1Data = BlueprintData::fromArray([
        'table_name' => 'products',
        'model_name' => 'Product',
        'columns' => [
            ['name' => 'name', 'type' => 'string'],
        ],
    ]);

    $migrationPath1 = (new MigrationGenerator)->generate($blueprint1Data);
    $modelPath1 = (new ModelGenerator)->generate($blueprint1Data);
    migrateBlueprintsTableTestMigration($migrationPath1);

    $blueprint1 = Blueprint::create([
        'table_name' => 'products',
        'model_name' => 'Product',
        'primary_key_type' => 'id',
        'columns' => [['name' => 'name', 'type' => 'string']],
        'soft_deletes' => false,
    ]);

    // Create second blueprint with files
    $blueprint2Data = BlueprintData::fromArray([
        'table_name' => 'articles',
        'model_name' => 'Article',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $migrationPath2 = (new MigrationGenerator)->generate($blueprint2Data);
    $modelPath2 = (new ModelGenerator)->generate($blueprint2Data);
    migrateBlueprintsTableTestMigration($migrationPath2);

    $blueprint2 = Blueprint::create([
        'table_name' => 'articles',
        'model_name' => 'Article',
        'primary_key_type' => 'id',
        'columns' => [['name' => 'title', 'type' => 'string']],
        'soft_deletes' => false,
    ]);

    // Verify both exist
    expect(Schema::hasTable('products'))->toBeTrue();
    expect(Schema::hasTable('articles'))->toBeTrue();
    expect(File::exists($modelPath1))->toBeTrue();
    expect(File::exists($modelPath2))->toBeTrue();

    // Delete first blueprint
    app(BlueprintDeletionService::class)->deleteBlueprintAndArtifacts($blueprint1);

    // Verify first is deleted but second remains
    expect(Blueprint::find($blueprint1->id))->toBeNull();
    expect(Blueprint::find($blueprint2->id))->not->toBeNull();
    expect(Schema::hasTable('products'))->toBeFalse();
    expect(Schema::hasTable('articles'))->toBeTrue();
    expect(File::exists($modelPath1))->toBeFalse();
    expect(File::exists($modelPath2))->toBeTrue();

    // Delete second blueprint
    app(BlueprintDeletionService::class)->deleteBlueprintAndArtifacts($blueprint2);

    // Verify second is also deleted
    expect(Blueprint::find($blueprint2->id))->toBeNull();
    expect(Schema::hasTable('articles'))->toBeFalse();
    expect(File::exists($modelPath2))->toBeFalse();
});
it('dispatches the first-tab activation event for the empty-state create action', function () {
    $component = \Mockery::mock(BlueprintsTable::class)->makePartial();
    $component->shouldReceive('dispatch')
        ->once()
        ->with('activate-first-tab');

    $component->activateFirstTab();
});

it('redirects to the panel root after deleting a blueprint', function () {
    $blueprint = Blueprint::create([
        'table_name' => 'products',
        'model_name' => 'Product',
        'primary_key_type' => 'id',
        'columns' => [
            ['name' => 'name', 'type' => 'string'],
        ],
        'soft_deletes' => false,
    ]);

    $component = \Mockery::mock(BlueprintsTable::class)->makePartial();
    $component->shouldReceive('redirect')
        ->once()
        ->with(url('/admin'), true);

    $component->deleteBlueprint($blueprint);

    expect(Blueprint::find($blueprint->id))->toBeNull();
});

it('mounts registered record actions in the blueprints table', function () {
    app(ArchitectUiExtensionRegistry::class)
        ->registerBlueprintsTableRecordActions(fn (): Action => Action::make('revision_history'));

    $component = app(BlueprintsTable::class);
    $table = $component->table(Table::make($component));

    expect($table->getAction('revision_history'))->toBeInstanceOf(Action::class);
});

it('can delete only the stored blueprint snapshot without deleting generated artifacts', function () {
    $blueprintData = BlueprintData::fromArray([
        'table_name' => 'products',
        'model_name' => 'Product',
        'columns' => [
            ['name' => 'name', 'type' => 'string'],
        ],
        'gen_factory' => false,
        'gen_seeder' => false,
        'gen_resource' => false,
    ]);

    $migrationPath = (new MigrationGenerator)->generate($blueprintData);
    $modelPath = (new ModelGenerator)->generate($blueprintData);

    migrateBlueprintsTableTestMigration($migrationPath);

    $blueprint = Blueprint::create([
        'table_name' => 'products',
        'model_name' => 'Product',
        'primary_key_type' => 'id',
        'columns' => [['name' => 'name', 'type' => 'string']],
        'soft_deletes' => false,
    ]);

    app(BlueprintDeletionService::class)->deleteSnapshotOnly($blueprint);

    expect(Blueprint::find($blueprint->id))->toBeNull()
        ->and(Schema::hasTable('products'))->toBeTrue()
        ->and(File::exists($modelPath))->toBeTrue()
        ->and(File::exists($migrationPath))->toBeTrue();
});

function blueprintsTableModelsNamespace(): string
{
    return 'App\\Testing\\BlueprintsTable\\Models';
}

function blueprintsTableFactoriesNamespace(): string
{
    return 'Database\\Testing\\BlueprintsTable\\Factories';
}

function blueprintsTableSeedersNamespace(): string
{
    return 'Database\\Testing\\BlueprintsTable\\Seeders';
}

function blueprintsTableResourcesNamespace(): string
{
    return 'App\\Testing\\BlueprintsTable\\Filament\\Resources';
}

function blueprintsTableModelsRoot(): string
{
    return dirname(GenerationPathResolver::model('Product'));
}

function blueprintsTableFactoriesRoot(): string
{
    return dirname(GenerationPathResolver::factory('ProductFactory'));
}

function blueprintsTableSeedersRoot(): string
{
    return dirname(GenerationPathResolver::seeder('ProductSeeder'));
}

function blueprintsTableResourcesRoot(): string
{
    return dirname(dirname(GenerationPathResolver::resource('ProductResource')));
}

function migrateBlueprintsTableTestMigration(string $path): void
{
    Artisan::call('migrate', [
        '--path' => $path,
        '--realpath' => true,
    ]);
}
